import Link from 'next/link'
import type { Game } from '@/lib/api'
import { GameCard } from '../game-card'
import { HOME_COPY } from './copy'

/**
 * The games worth putting on the front page, in a deliberate order.
 *
 * Named rather than ranked, because a launch catalog ranks badly: sorting by
 * server count would put whichever game we happened to import first at the top
 * and leave the front page rearranging itself every time an import lands. These
 * are the titles the site is being built around.
 *
 * It is a preference, not a whitelist — anything named but not in the catalog is
 * skipped, and the rest of the row is filled by whatever has the most servers,
 * so a new game appears here on its own once it has any.
 */
const PRIORITY = [
  'rust',
  'minecraft',
  'counter-strike-2',
  'dayz',
  'ark-survival-ascended',
  'team-fortress-2',
]

/** One row on a big screen, and a number that divides evenly at every breakpoint. */
const SHOWN = 6

export function PopularGamesSection({ games }: { games: Game[] }) {
  const featured = pick(games)

  if (featured.length === 0) return null

  return (
    <section aria-labelledby="section-games" className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
        <div className="min-w-0">
          <h2 id="section-games" className="font-display text-xl font-black tracking-tight uppercase">
            {HOME_COPY.games.title}
          </h2>
          <p className="mt-1 text-sm text-muted">{HOME_COPY.games.description}</p>
        </div>

        <Link
          href="/games"
          className="shrink-0 text-sm font-medium text-brand transition-colors hover:underline"
        >
          {HOME_COPY.games.viewAll}
        </Link>
      </div>

      <ul className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        {featured.map((game, index) => (
          <li key={game.slug}>
            {/* The first row is the largest thing on the first screen, so its
                covers are the LCP candidate. Only the first three: on a phone
                that is what is visible, and marking all six would be marking
                none. */}
            <GameCard game={game} href={`/games/${game.slug}`} priority={index < 3} />
          </li>
        ))}
      </ul>
    </section>
  )
}

function pick(games: Game[]): Game[] {
  const withServers = games.filter((game) => game.counters.servers > 0)
  const bySlug = new Map(withServers.map((game) => [game.slug, game]))

  const named = PRIORITY.map((slug) => bySlug.get(slug)).filter((game): game is Game => Boolean(game))

  const rest = withServers
    .filter((game) => !PRIORITY.includes(game.slug))
    .sort((a, b) => b.counters.servers - a.counters.servers)

  return [...named, ...rest].slice(0, SHOWN)
}
