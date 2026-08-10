import { notFound } from 'next/navigation'
import { Suspense } from 'react'
import type { GameDetail, ServerFilters } from '@/lib/api'
import { getGame, getRecentVotes, getServers } from '@/lib/data'
import { GameHero } from './game-hero'
import { RecentVotes } from './recent-votes'
import { ServerBrowser } from './server-browser'

/**
 * The listing body shared by the game page and every facet page under it
 * (/games/rust, /pvp, /version/2631, /country/germany). One component so a
 * facet page is never a thinner copy of the main one.
 *
 * Nothing here is cached. The game, its facet counts, the first page of servers
 * and the votes rail are all read when the request arrives, which is what makes
 * a server added a minute ago findable and a chip count true. The three reads
 * sit behind their own Suspense boundaries so the route still prerenders a
 * shell: the hero lands as soon as the game call returns, the rail whenever the
 * votes do, and neither waits on the listing.
 *
 * The first page of servers is still fetched on the server and handed to the
 * browser as its starting state, so the list is in the HTML that ships — for
 * anyone without JavaScript, crawlers included. Streaming does not change that:
 * it is the same response, arriving in pieces.
 */

/** What this particular listing calls itself, once the game is known. */
export type Description = {
  heading: string
  /** The last breadcrumb step. */
  crumb: string
  /** What the locked facet is called, for the pill that clears it. Absent on the game's own page. */
  facetLabel?: string
}

/**
 * Names the page from the game it turned out to be.
 *
 * A function rather than three strings because every one of them needs data
 * that is no longer available before rendering: the game's name, and for a
 * facet route the facet's own name out of `game.facets`. Returning null is how
 * a facet route says "this game has no such mode" — the caller's notFound.
 */
type Describe = (game: GameDetail) => Description | null

/** What a route turns out to be, once its params have resolved. */
export type Route = {
  gameSlug: string
  /** Fixed by the route. Everything else the visitor picks in the browser. */
  filters?: ServerFilters
  describe: Describe
}

/**
 * A thunk, and that is the whole point of it.
 *
 * The caller must not `await params` to build this — under Partial Prefetching
 * a route's App Shell is shared by every link pointing at it, so reading URL
 * data outside a `<Suspense>` boundary ties the shell to one URL and the route
 * loses the sharing. Facet pages have no `generateStaticParams` and so no
 * per-URL shell to fall back on; they were the ones Next flagged. Deferring the
 * read into the boundaries below keeps the shell free of it, and the slugs
 * arrive with the data they were needed for anyway.
 */
type Resolve = () => Promise<Route>

/** Must match ServerBrowser's own page size, or Load more would skip rows. */
const PER_PAGE = 25

export function GameListing({ route }: { route: Resolve }) {
  return (
    <div className="space-y-6">
      <Suspense fallback={<HeroSkeleton />}>
        <Hero route={route} />
      </Suspense>

      {/* The rail follows the listing until there is genuinely room beside it:
          squeezed onto a 1280px screen it would cost the table two columns. */}
      <div className="grid min-w-0 gap-6 2xl:grid-cols-[minmax(0,1fr)_18rem]">
        <div className="min-w-0 space-y-8">
          <Suspense fallback={<BrowserSkeleton />}>
            <Listing route={route} />
          </Suspense>
        </div>

        <aside className="min-w-0">
          <div className="2xl:sticky 2xl:top-20">
            {/* No fallback: the rail is hidden entirely when a game has no
                votes, so a skeleton here would promise something that may
                never arrive and then collapse the column when it does not. */}
            <Suspense fallback={null}>
              <Votes route={route} />
            </Suspense>
          </div>
        </aside>
      </div>
    </div>
  )
}

async function Hero({ route }: { route: Resolve }) {
  const { gameSlug, describe } = await route()
  const game = await getGame(gameSlug)
  const described = game && describe(game)

  if (!game || !described) notFound()

  return (
    <GameHero
      game={game}
      heading={described.heading}
      crumb={described.crumb}
      // A facet label is what a facet route has and the game's own page does
      // not — the same test line 132 uses to decide whether the description
      // belongs on this page.
      atGameRoot={!described.facetLabel}
    />
  )
}

async function Listing({ route }: { route: Resolve }) {
  const { gameSlug, filters = {}, describe } = await route()

  const [game, listing] = await Promise.all([
    // Already in flight for the hero, and deduped for the length of this
    // request — see the React `cache` wrappers in lib/data.
    getGame(gameSlug),
    getServers(gameSlug, { ...filters, sort: 'rank', per_page: PER_PAGE }),
  ])

  const described = game && describe(game)

  if (!game || !described || !listing) notFound()

  return (
    <>
      <ServerBrowser
        game={game}
        initial={listing}
        lockedMode={filters.mode}
        lockedVersion={filters.version}
        lockedCountry={filters.country}
        lockedLabel={described.facetLabel}
      />

      {/* Only on the game's own page: the same paragraphs repeated under
          every facet URL is duplicate content, and each facet page already
          says what it is in its title and heading. */}
      {!described.facetLabel && game.description && (
        <section className="rounded-2xl border border-line bg-surface p-5 sm:p-6">
          <h2 className="font-display text-lg font-bold tracking-tight">About {game.name}</h2>
          <p className="mt-3 max-w-3xl leading-relaxed text-muted">{game.description}</p>
        </section>
      )}
    </>
  )
}

async function Votes({ route }: { route: Resolve }) {
  const { gameSlug } = await route()

  return <RecentVotes votes={await getRecentVotes(gameSlug)} />
}

/* The same shapes in the same places as the real thing — and the same ones
   loading.tsx uses, so a page load and a navigation into the listing settle
   identically instead of showing two different waits. */

function HeroSkeleton() {
  return <div className="h-44 animate-pulse rounded-2xl bg-surface" aria-hidden />
}

function BrowserSkeleton() {
  return (
    <div className="space-y-4" aria-busy="true" aria-label="Loading servers">
      <div className="h-44 animate-pulse rounded-2xl bg-surface" />
      <div className="h-10 w-72 animate-pulse rounded-xl bg-surface" />
      <div className="h-96 animate-pulse rounded-2xl bg-surface" />
    </div>
  )
}
