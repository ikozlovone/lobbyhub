import { notFound } from 'next/navigation'
import type { ServerFilters } from '@/lib/api'
import { getGame, getRecentVotes, getServers } from '@/lib/data'
import { GameHero } from './game-hero'
import { RecentVotes } from './recent-votes'
import { ServerBrowser } from './server-browser'

/**
 * The listing body shared by the game page and every facet page under it
 * (/games/rust, /pvp, /version/2631, /country/germany). One component so a
 * facet page is never a thinner copy of the main one.
 *
 * The first page of servers is fetched here, on the server, and handed to the
 * browser as its starting state: the list is in the HTML that ships, and the
 * interactive layer takes over from there without refetching what it was given.
 */

/** Must match ServerBrowser's own page size, or Load more would skip rows. */
const PER_PAGE = 25

export async function GameListing({
  gameSlug,
  filters = {},
  heading,
  crumb = 'Servers',
  facetLabel,
}: {
  gameSlug: string
  /** Fixed by the route. Everything else the visitor picks in the browser. */
  filters?: ServerFilters
  heading: string
  crumb?: string
  /** What the locked facet is called, for the pill that clears it. */
  facetLabel?: string
}) {
  const [game, listing, votes] = await Promise.all([
    getGame(gameSlug),
    getServers(gameSlug, { ...filters, sort: 'rank', per_page: PER_PAGE }),
    getRecentVotes(gameSlug),
  ])

  if (!game || !listing) notFound()

  return (
    <div className="space-y-6">
      <GameHero game={game} heading={heading} crumb={crumb} />

      {/* The rail follows the listing until there is genuinely room beside it:
          squeezed onto a 1280px screen it would cost the table two columns. */}
      <div className="grid min-w-0 gap-6 2xl:grid-cols-[minmax(0,1fr)_18rem]">
        <div className="min-w-0 space-y-8">
          <ServerBrowser
            game={game}
            initial={listing}
            lockedMode={filters.mode}
            lockedVersion={filters.version}
            lockedCountry={filters.country}
            lockedLabel={facetLabel}
          />

          {/* Only on the game's own page: the same paragraphs repeated under
              every facet URL is duplicate content, and each facet page already
              says what it is in its title and heading. */}
          {!facetLabel && game.description && (
            <section className="rounded-2xl border border-line bg-surface p-5 sm:p-6">
              <h2 className="font-display text-lg font-bold tracking-tight">
                About {game.name}
              </h2>
              <p className="mt-3 max-w-3xl leading-relaxed text-muted">{game.description}</p>
            </section>
          )}
        </div>

        <aside className="min-w-0">
          <div className="2xl:sticky 2xl:top-20">
            <RecentVotes votes={votes} />
          </div>
        </aside>
      </div>
    </div>
  )
}
