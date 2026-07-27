import Link from 'next/link'
import { getGames } from '@/lib/data'

/**
 * Persistent game navigation.
 *
 * Games with servers first, then the rest greyed back: the catalog has 27 games
 * and only a few have anything in them yet, and pretending otherwise wastes the
 * most valuable strip of the layout.
 */
export async function Sidebar() {
  const games = await getGames()
  const withServers = games.filter((game) => game.counters.servers > 0)
  const empty = games.filter((game) => game.counters.servers === 0)

  return (
    <nav aria-label="Games" className="space-y-6 text-sm">
      <div>
        <p className="font-display mb-2 px-2 text-[11px] font-bold tracking-widest text-subtle uppercase">
          Games
        </p>
        <ul>
          {withServers.map((game) => (
            <li key={game.slug}>
              <Link
                href={`/games/${game.slug}`}
                className="group flex cursor-pointer items-center gap-2.5 rounded px-2 py-1.5 transition-colors hover:bg-surface-2"
              >
                <Thumb game={game} />
                <span className="min-w-0 flex-1 truncate text-muted transition-colors group-hover:text-fg">
                  {game.name}
                </span>
                <span className="tabular text-[11px] text-subtle">{game.counters.servers}</span>
              </Link>
            </li>
          ))}
        </ul>
      </div>

      {empty.length > 0 && (
        <div>
          <p className="font-display mb-2 px-2 text-[11px] font-bold tracking-widest text-subtle uppercase">
            No servers yet
          </p>
          <ul>
            {empty.map((game) => (
              <li key={game.slug}>
                <Link
                  href={`/games/${game.slug}`}
                  className="group flex cursor-pointer items-center gap-2.5 rounded px-2 py-1.5 transition-colors hover:bg-surface-2"
                >
                  <Thumb game={game} muted />
                  <span className="min-w-0 flex-1 truncate text-subtle transition-colors group-hover:text-muted">
                    {game.name}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        </div>
      )}
    </nav>
  )
}

function Thumb({
  game,
  muted,
}: {
  game: Awaited<ReturnType<typeof getGames>>[number]
  muted?: boolean
}) {
  return game.cover ? (
    <img
      src={game.cover}
      alt=""
      width={28}
      height={28}
      loading="lazy"
      className={`size-7 shrink-0 rounded object-cover ${muted ? 'opacity-40' : ''}`}
    />
  ) : (
    <span
      aria-hidden
      className={`size-7 shrink-0 rounded ${muted ? 'opacity-40' : ''}`}
      style={{ backgroundColor: game.accent_color ?? 'var(--color-line-strong)' }}
    />
  )
}
