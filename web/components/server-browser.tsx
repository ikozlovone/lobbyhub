'use client'

import Link from 'next/link'
import { useCallback, useEffect, useRef, useState } from 'react'
import {
  fetchServers,
  type Facet,
  type GameDetail,
  type Paginated,
  type Server,
  type ServerSort,
  type StatusFilter,
} from '@/lib/api'
import { Icon } from './icons'
import { LiveProvider } from './live-provider'
import { ServerCard } from './server-card'
import { ServerTable } from './server-table'
import { useToast } from './toast'

/**
 * The listing, and everything that narrows it.
 *
 * Two layers, like the rest of the site. The first page arrives inside the
 * cached page shell, rendered on the server — so the list is in the HTML for
 * anyone who does not run JavaScript, crawlers included. Every filter after
 * that is answered by the API directly, because the alternative is a full
 * navigation and a re-render of the shell to change one chip.
 *
 * Filter state is mirrored into the query string with replaceState rather than
 * a router push: the page it belongs to is prerendered and cached, and pushing
 * would ask Next to re-resolve a route that has not changed. The cost is that
 * restoring a shared link takes one extra request — see the mount effect.
 */

type View = 'table' | 'grid'

type Query = {
  status: StatusFilter | ''
  map: string
  country: string
  mode: string
  version: string
  q: string
  sort: ServerSort
}

const DEFAULTS: Query = {
  status: '',
  map: '',
  country: '',
  mode: '',
  version: '',
  q: '',
  sort: 'rank',
}

/**
 * "All servers" is our ranking — votes and measured activity — which is the
 * thing this site is for. The other two are the questions it does not answer.
 */
const TABS: { sort: ServerSort; label: string }[] = [
  { sort: 'rank', label: 'All servers' },
  { sort: 'players', label: 'Most players' },
  { sort: 'newest', label: 'New' },
]

const PER_PAGE = 25

/**
 * How many pages scrolling may fetch before the page asks for a click.
 *
 * Endless is the wrong shape here. A busy game has thousands of servers, none
 * of these rows are virtualised, and — below 2xl — the Recent votes column sits
 * *after* the listing, along with the footer. A list that regrows every time you
 * approach its end puts all of that permanently out of reach.
 *
 * Five pages is a long browse; after that "Keep loading" says the rest is still
 * there, and clicking it starts the count over. Nothing is capped, only made
 * deliberate.
 */
const AUTO_PAGES = 5

/** Long enough that typing a name is one request, short enough to feel live. */
const TYPING_MS = 350

export function ServerBrowser({
  game,
  initial,
  lockedMode = '',
  lockedVersion = '',
  lockedCountry = '',
  lockedLabel,
}: {
  game: GameDetail
  initial: Paginated<Server>
  /*
   * A facet route (/games/rust/country/germany) is a real page with its own
   * title and canonical, so its filter is fixed rather than a chip you can
   * change: it shows as a removable pill that leads back to the whole game.
   */
  lockedMode?: string
  lockedVersion?: string
  lockedCountry?: string
  lockedLabel?: string
}) {
  const [query, setQuery] = useState(DEFAULTS)
  const [term, setTerm] = useState('')
  const [view, setView] = useState<View>('table')
  const [servers, setServers] = useState(initial.data)
  const [meta, setMeta] = useState(initial.meta)
  const [busy, setBusy] = useState(false)
  // Pages that arrived by scrolling since the last deliberate act. Reset by a
  // filter change or a click on Load more — see AUTO_PAGES.
  const [autoLoaded, setAutoLoaded] = useState(0)
  const sentinel = useRef<HTMLDivElement>(null)
  const toast = useToast()

  const steam = game.monitoring.protocol === 'source'

  const request = useCallback(
    (next: Query, page: number) =>
      fetchServers(
        game.slug,
        {
          mode: lockedMode || next.mode || undefined,
          version: lockedVersion || next.version || undefined,
          country: lockedCountry || next.country || undefined,
          status: next.status || undefined,
          map: next.map || undefined,
          q: next.q.trim() || undefined,
          sort: next.sort,
          page,
          per_page: PER_PAGE,
        },
        { cache: 'no-store' },
      ),
    [game.slug, lockedMode, lockedVersion, lockedCountry],
  )

  /*
   * Which request is the current one.
   *
   * Scrolling can have a page in flight when a chip is pressed, and that reply
   * still believes it is appending to the list that asked for it. Without this,
   * it lands *after* the filtered page one and staples rows from the old query
   * onto the new list — twenty-five servers matching the filter, then sixteen
   * that do not. Anything not holding the latest ticket is dropped on arrival.
   */
  const ticket = useRef(0)

  const load = useCallback(
    async (next: Query, page: number, append: boolean) => {
      const mine = ++ticket.current

      setBusy(true)

      try {
        const result = await request(next, page)

        if (mine !== ticket.current) return

        setServers((current) => (append ? [...current, ...result.data] : result.data))
        setMeta(result.meta)
      } catch {
        if (mine === ticket.current) {
          toast.error('Error', 'Could not load the list. Try again in a moment.')
        }
      } finally {
        if (mine === ticket.current) setBusy(false)
      }
    },
    [request, toast],
  )

  function apply(patch: Partial<Query>) {
    const next = { ...query, ...patch }

    setQuery(next)
    setAutoLoaded(0)
    writeUrl(next, view)
    void load(next, 1, false)
  }

  function loadMore(automatic: boolean) {
    setAutoLoaded((count) => (automatic ? count + 1 : 0))
    void load(query, meta.current_page + 1, true)
  }

  function changeView(next: View) {
    setView(next)
    writeUrl(query, next)
  }

  // Typing is the one control that must not fire per keystroke.
  useEffect(() => {
    if (term === query.q) return

    const timer = setTimeout(() => apply({ q: term }), TYPING_MS)

    return () => clearTimeout(timer)
    // `apply` closes over the current query by design; re-running this on every
    // render of it would restart the timer and never let it fire.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [term])

  // Restore a shared link once, after hydration.
  //
  // The alternative is reading searchParams on the server, which would make the
  // whole route dynamic and throw away the prerendering the catalog runs on.
  //
  // This goes to `request` rather than `load` for two reasons: nothing here
  // should flash the Refresh spinner on a page that is still loading, and every
  // piece of state is committed inside the promise, together with the rows it
  // describes — chips that turn on before their list arrives would be lying.
  const restored = useRef(false)

  useEffect(() => {
    if (restored.current) return
    restored.current = true

    const params = new URLSearchParams(window.location.search)
    const fromUrl = readUrl(params)
    const fromUrlView: View = params.get('view') === 'grid' ? 'grid' : 'table'

    if (isDefault(fromUrl) && fromUrlView === 'table') return

    request(fromUrl, 1)
      .then((result) => {
        setQuery(fromUrl)
        setTerm(fromUrl.q)
        setView(fromUrlView)
        setServers(result.data)
        setMeta(result.meta)
      })
      // A bad link falls back to the list already on the page.
      .catch(() => {})
  }, [request])

  const filtered = !isDefault(query)
  const more = meta.current_page < meta.last_page
  const scrolls = more && autoLoaded < AUTO_PAGES

  /*
   * Fetch the next page when the end of the list comes into view.
   *
   * The observer is watching a marker that sits above the button, with room to
   * spare, so the rows are usually there before the last visible one is read.
   * Nothing here replaces the button: it stays for anyone arriving by keyboard,
   * and it is what a browser without IntersectionObserver falls back to.
   */
  useEffect(() => {
    const element = sentinel.current

    if (!element || !scrolls || busy) return

    const observer = new IntersectionObserver(
      (entries) => entries[0]?.isIntersecting && loadMore(true),
      // Roughly a screen early: a list that has already stopped is a list the
      // reader noticed the end of.
      { rootMargin: '700px' },
    )

    observer.observe(element)

    return () => observer.disconnect()
    // `loadMore` reads the current page and filters off state that is already
    // in these dependencies; adding the function itself would rebuild the
    // observer on every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [scrolls, busy, meta.current_page, query])

  return (
    <div className="space-y-4">
      <div className="space-y-3 rounded-2xl border border-line bg-surface p-3 sm:p-4">
        <div className="relative">
          <span aria-hidden className="absolute top-1/2 left-4 -translate-y-1/2 text-subtle">
            <Icon.search className="size-5" />
          </span>
          <label htmlFor="server-search" className="sr-only">
            Search {game.name} servers by name
          </label>
          <input
            id="server-search"
            type="search"
            value={term}
            onChange={(event) => setTerm(event.target.value)}
            placeholder="Search by name"
            autoComplete="off"
            className="w-full rounded-xl border border-line bg-bg py-3 pr-4 pl-11 outline-none transition-colors placeholder:text-subtle"
          />
        </div>

        {/* Counts are for the whole game, not for what is left after the other
            chips — they describe the game, and a number that moves as you
            narrow answers a question nobody asked. */}
        <div className="flex flex-wrap gap-2">
          {game.facets.statuses.map((status) => (
            <Chip
              key={status.slug}
              label={status.name}
              count={status.servers_count}
              active={query.status === status.slug}
              onClick={() =>
                apply({ status: query.status === status.slug ? '' : (status.slug as StatusFilter) })
              }
            />
          ))}
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {lockedLabel && (
            <Link
              href={`/games/${game.slug}`}
              className="flex cursor-pointer items-center gap-1.5 rounded-lg border border-brand/40 bg-brand/10 px-3 py-2 text-sm text-fg transition-colors hover:bg-brand/20"
            >
              {lockedLabel}
              <Icon.close className="size-3.5 text-subtle" />
            </Link>
          )}

          {!lockedCountry && (
            <Picker
              label="Location"
              value={query.country}
              options={game.facets.countries}
              onChange={(country) => apply({ country })}
            />
          )}
          {!lockedMode && (
            <Picker
              label="Mode"
              value={query.mode}
              options={game.facets.modes}
              onChange={(mode) => apply({ mode })}
            />
          )}
          <Picker
            label="Map"
            value={query.map}
            options={game.facets.maps}
            onChange={(map) => apply({ map })}
          />
          {!lockedVersion && (
            <Picker
              label="Version"
              value={query.version}
              options={game.facets.versions}
              onChange={(version) => apply({ version })}
            />
          )}
        </div>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-1 rounded-xl border border-line bg-surface p-1">
          {TABS.map((tab) => (
            <button
              key={tab.sort}
              type="button"
              onClick={() => apply({ sort: tab.sort })}
              aria-pressed={query.sort === tab.sort}
              className={`cursor-pointer rounded-lg px-3 py-1.5 text-sm transition-colors ${
                query.sort === tab.sort
                  ? 'bg-brand font-medium text-white'
                  : 'text-muted hover:text-fg'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        <div className="flex items-center gap-2">
          <div className="flex items-center gap-1 rounded-xl border border-line bg-surface p-1">
            <ViewButton current={view} value="table" onClick={changeView} label="Table" />
            <ViewButton current={view} value="grid" onClick={changeView} label="Grid" />
          </div>

          <button
            type="button"
            onClick={() => void load(query, 1, false)}
            disabled={busy}
            className="flex cursor-pointer items-center gap-2 rounded-xl border border-line bg-surface px-3 py-2 text-sm text-muted transition-colors hover:text-fg disabled:cursor-not-allowed disabled:opacity-60"
          >
            <Icon.refresh className={busy ? 'animate-spin' : undefined} />
            Refresh
          </button>
        </div>
      </div>

      <p className="flex items-center gap-2 text-xs text-subtle" aria-live="polite">
        <span className="tabular">{meta.total.toLocaleString('en-US')}</span>
        {meta.total === 1 ? 'server' : 'servers'}
        {filtered && (
          <button
            type="button"
            onClick={() => {
              setTerm('')
              apply(DEFAULTS)
            }}
            className="cursor-pointer text-brand transition-colors hover:underline"
          >
            Clear filters
          </button>
        )}
      </p>

      {servers.length === 0 ? (
        /* A game nobody has added a server to yet is not a search that came
           back empty, and telling an owner to loosen filters they never set
           would send them looking for a control that is not there. */
        <div className="rounded-2xl border border-line bg-surface px-4 py-16 text-center text-sm text-subtle">
          {filtered ? (
            <p>No servers match these filters.</p>
          ) : (
            <p>
              No {game.name} servers listed yet.{' '}
              <Link
                href={`/games/${game.slug}/add-server`}
                className="cursor-pointer font-medium text-brand hover:underline"
              >
                Add the first one
              </Link>
              .
            </p>
          )}
        </div>
      ) : (
        <LiveProvider slugs={servers.map((server) => server.slug)}>
          {view === 'table' ? (
            <div className="overflow-hidden rounded-2xl border border-line bg-surface">
              <ServerTable servers={servers} steam={steam} onPickMap={(map) => apply({ map })} />
            </div>
          ) : (
            <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {servers.map((server) => (
                <li key={server.slug}>
                  <ServerCard server={server} steam={steam} />
                </li>
              ))}
            </ul>
          )}
        </LiveProvider>
      )}

      {more && (
        <>
          <div ref={sentinel} aria-hidden />

          <button
            type="button"
            onClick={() => loadMore(false)}
            disabled={busy}
            className="w-full cursor-pointer rounded-xl border border-line bg-surface py-3 text-sm font-medium text-muted transition-colors hover:border-line-strong hover:text-fg disabled:cursor-not-allowed disabled:opacity-60"
          >
            {busy ? 'Loading…' : scrolls ? 'Load more' : 'Keep loading'}
          </button>
        </>
      )}

      {/* Rows appear without anyone asking, so the count is announced — for a
          screen reader that is the only sign the page grew. */}
      <p aria-live="polite" className="sr-only">
        {`Showing ${servers.length} of ${meta.total} servers`}
      </p>
    </div>
  )
}

function Chip({
  label,
  count,
  active,
  onClick,
}: {
  label: string
  count: number
  active: boolean
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      // A chip that can only ever return nothing is offered, so the page still
      // says "no server here is full", but it is not clickable.
      disabled={count === 0 && !active}
      aria-pressed={active}
      className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-40 ${
        active
          ? 'border-brand bg-brand/15 text-fg'
          : 'border-line bg-bg text-muted hover:border-line-strong hover:text-fg'
      }`}
    >
      {label}
      <span className="tabular rounded bg-surface-2 px-1.5 py-0.5 text-[11px] text-subtle">
        {count.toLocaleString('en-US')}
      </span>
    </button>
  )
}

/**
 * A facet as a native select.
 *
 * Native rather than a custom popover: these lists run to dozens of entries,
 * and the platform already gives them a keyboard, type-ahead and a full-screen
 * picker on phones. `color-scheme: dark` on the document is what keeps the
 * browser's own menu from opening white.
 */
function Picker({
  label,
  value,
  options,
  onChange,
}: {
  label: string
  value: string
  options: Facet[]
  onChange: (value: string) => void
}) {
  if (options.length === 0) return null

  const chosen = options.find((option) => option.slug === value)

  return (
    <label
      className={`relative flex cursor-pointer items-center gap-2 rounded-lg border py-2 pr-8 pl-3 text-sm transition-colors ${
        value ? 'border-brand bg-brand/15 text-fg' : 'border-line bg-bg text-muted hover:border-line-strong'
      }`}
    >
      <span className="max-w-40 truncate">{chosen ? chosen.name : label}</span>
      <span className="tabular rounded bg-surface-2 px-1.5 py-0.5 text-[11px] text-subtle">
        {options.length.toLocaleString('en-US')}
      </span>
      <span aria-hidden className="pointer-events-none absolute right-2.5 text-subtle">
        ▾
      </span>

      <select
        value={value}
        onChange={(event) => onChange(event.target.value)}
        aria-label={label}
        className="absolute inset-0 cursor-pointer opacity-0"
      >
        <option value="">Any {label.toLowerCase()}</option>
        {options.map((option) => (
          <option key={option.slug} value={option.slug}>
            {option.name} ({option.servers_count})
          </option>
        ))}
      </select>
    </label>
  )
}

function ViewButton({
  current,
  value,
  label,
  onClick,
}: {
  current: View
  value: View
  label: string
  onClick: (view: View) => void
}) {
  const Mark = value === 'table' ? Icon.mode : Icon.boxes

  return (
    <button
      type="button"
      onClick={() => onClick(value)}
      aria-pressed={current === value}
      className={`flex cursor-pointer items-center gap-2 rounded-lg px-3 py-1.5 text-sm transition-colors ${
        current === value ? 'bg-surface-2 text-fg' : 'text-muted hover:text-fg'
      }`}
    >
      <Mark />
      <span className="hidden sm:inline">{label}</span>
    </button>
  )
}

function isDefault(query: Query): boolean {
  return (Object.keys(DEFAULTS) as (keyof Query)[]).every((key) => query[key] === DEFAULTS[key])
}

function readUrl(params: URLSearchParams): Query {
  const sort = params.get('sort')

  return {
    status: (params.get('status') ?? '') as StatusFilter | '',
    map: params.get('map') ?? '',
    country: params.get('country') ?? '',
    mode: params.get('mode') ?? '',
    version: params.get('version') ?? '',
    q: params.get('q') ?? '',
    // Anything unrecognised falls back rather than reaching the API, which
    // would answer a shared link with a validation error.
    sort: TABS.some((tab) => tab.sort === sort) ? (sort as ServerSort) : DEFAULTS.sort,
  }
}

function writeUrl(query: Query, view: View) {
  const params = new URLSearchParams()

  const keep = (key: string, value: string) => value !== '' && params.set(key, value)

  keep('status', query.status)
  keep('map', query.map)
  keep('country', query.country)
  keep('mode', query.mode)
  keep('version', query.version)
  keep('q', query.q.trim())
  keep('sort', query.sort === DEFAULTS.sort ? '' : query.sort)
  keep('view', view === 'table' ? '' : view)

  const search = params.toString()

  window.history.replaceState(null, '', search ? `?${search}` : window.location.pathname)
}
