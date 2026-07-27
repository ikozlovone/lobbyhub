import Link from 'next/link'
import { notFound } from 'next/navigation'
import type { ServerFilters } from '@/lib/api'
import { getGame, getServers } from '@/lib/data'
import { LiveProvider } from './live-provider'
import { ServerRow } from './server-row'

/**
 * The listing body shared by the game page and every facet page under it
 * (/games/minecraft, /survival, /version/1-21, /country/germany). One component
 * so a facet page is never a thinner copy of the main one.
 */
export async function GameListing({
  gameSlug,
  filters = {},
  heading,
  intro,
}: {
  gameSlug: string
  filters?: ServerFilters
  heading: string
  intro?: string
}) {
  const [game, listing] = await Promise.all([
    getGame(gameSlug),
    getServers(gameSlug, filters),
  ])

  if (!game || !listing) notFound()

  const servers = listing.data
  const slugs = servers.map((server) => server.slug)

  return (
    <div className="space-y-6">
      <header className="space-y-2">
        <nav aria-label="Breadcrumb" className="text-xs text-subtle">
          <Link href="/" className="hover:text-fg">
            Home
          </Link>
          <span className="mx-1.5">/</span>
          <Link href={`/games/${game.slug}`} className="hover:text-fg">
            {game.name}
          </Link>
        </nav>
        <h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl">{heading}</h1>
        {intro && <p className="max-w-3xl text-sm text-muted">{intro}</p>}
        <p className="tabular text-sm text-subtle">
          {game.counters.servers.toLocaleString('en-US')} servers ·{' '}
          {game.counters.players_online.toLocaleString('en-US')} players online
        </p>
      </header>

      <div className="grid gap-6 lg:grid-cols-[15rem_1fr]">
        <aside className="space-y-6">
          <FacetGroup
            title="Modes"
            items={game.facets.modes.map((mode) => ({
              href: `/games/${game.slug}/${mode.slug}`,
              label: mode.name,
              count: mode.servers_count,
              active: filters.mode === mode.slug,
            }))}
          />
          {game.has_versions && (
            <FacetGroup
              title="Versions"
              items={game.facets.versions.map((version) => ({
                href: `/games/${game.slug}/version/${version.slug}`,
                label: version.name,
                count: version.servers_count,
                active: filters.version === version.slug,
              }))}
            />
          )}
          <FacetGroup
            title="Countries"
            items={game.facets.countries.map((country) => ({
              href: `/games/${game.slug}/country/${country.slug}`,
              label: country.name,
              count: country.servers_count,
              active: filters.country === country.slug,
            }))}
          />
        </aside>

        <section className="rounded-lg border border-line bg-surface">
          <div className="hidden grid-cols-[2.5rem_1fr_7rem_10rem] gap-4 border-b border-line px-3 py-2 text-xs tracking-wide text-subtle uppercase sm:grid">
            <span>#</span>
            <span>Server</span>
            <span>Status</span>
            <span className="text-right">Players</span>
          </div>

          {servers.length === 0 ? (
            <p className="px-4 py-12 text-center text-sm text-subtle">
              No servers here yet.
            </p>
          ) : (
            <LiveProvider slugs={slugs}>
              {servers.map((server, index) => (
                <ServerRow key={server.slug} server={server} rank={index + 1} />
              ))}
            </LiveProvider>
          )}
        </section>
      </div>
    </div>
  )
}

function FacetGroup({
  title,
  items,
}: {
  title: string
  items: { href: string; label: string; count: number; active: boolean }[]
}) {
  if (items.length === 0) return null

  return (
    <div>
      <h2 className="mb-2 font-display text-xs font-bold tracking-wide text-muted uppercase">
        {title}
      </h2>
      <ul className="space-y-0.5">
        {items.map((item) => (
          <li key={item.href}>
            <Link
              href={item.href}
              aria-current={item.active ? 'page' : undefined}
              className={`flex cursor-pointer items-center justify-between rounded px-2 py-1.5 text-sm transition-colors ${
                item.active ? 'bg-surface-2 text-fg' : 'text-muted hover:bg-surface-2 hover:text-fg'
              }`}
            >
              <span className="truncate">{item.label}</span>
              <span className="tabular ml-2 shrink-0 text-xs text-subtle">{item.count}</span>
            </Link>
          </li>
        ))}
      </ul>
    </div>
  )
}
