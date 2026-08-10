import Link from 'next/link'
import type { Game } from '@/lib/api'

/**
 * One game, as a card.
 *
 * Lifted out of GameGrid so the home page's "Browse servers by game" can use
 * the same card the catalog does. A second implementation would be two places
 * to fix every time the counters or the artwork ratio change.
 *
 * Not a client component: it is a link and two numbers, and the grid it used to
 * live in only needs the browser for its filter box.
 */
export function GameCard({
  game,
  href,
  priority = false,
}: {
  game: Game
  href: string
  /**
   * Loads the cover eagerly. For the handful of cards above the fold on the
   * home page — the LCP candidate there is a game cover, and lazy-loading the
   * thing being measured is how a page loses the metric. Off everywhere else.
   */
  priority?: boolean
}) {
  const empty = game.counters.servers === 0

  return (
    <Link
      href={href}
      // Prefetched. The catalog shows every game at once, but they all lead to
      // the one /games/[game] route, and Partial Prefetching warms that route's
      // shell once however many cards point at it — see next.config.ts.
      className="group block h-full cursor-pointer overflow-hidden rounded-xl border border-line bg-surface transition-colors hover:border-line-strong"
    >
      {/* Steam header art is 460x215; the ratio is reserved either way so a
          missing cover cannot shift the grid while images load. */}
      <div
        className="relative aspect-[460/215] overflow-hidden"
        style={{ backgroundColor: game.accent_color ?? 'var(--color-surface-2)' }}
      >
        {game.cover ? (
          <img
            src={game.cover}
            alt=""
            width={460}
            height={215}
            loading={priority ? 'eager' : 'lazy'}
            fetchPriority={priority ? 'high' : undefined}
            className={`size-full object-cover transition-transform duration-300 group-hover:scale-[1.03] ${
              empty ? 'opacity-60' : ''
            }`}
          />
        ) : (
          <span className="font-display absolute inset-0 flex items-center justify-center px-2 text-center text-lg font-black text-white/90">
            {game.name}
          </span>
        )}
      </div>

      <div className="p-3">
        <h3 className="font-display truncate font-bold transition-colors group-hover:text-brand">
          {game.name}
        </h3>

        <dl className="mt-2 space-y-1 text-sm">
          <div className="flex justify-between gap-2">
            <dt className="text-subtle">Servers</dt>
            <dd className="tabular">{game.counters.servers.toLocaleString('en-US')}</dd>
          </div>
          <div className="flex justify-between gap-2">
            <dt className="text-subtle">Players on servers</dt>
            <dd className="tabular">{game.counters.players_online.toLocaleString('en-US')}</dd>
          </div>
        </dl>
      </div>
    </Link>
  )
}
