import Link from 'next/link'
import { getGames } from '@/lib/data'
import { Icon } from './icons'

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

  // The landmark is labelled for the whole rail, not just the games: it carries
  // two actions above them now, and a landmark called "Games" holding "Add
  // server" is one that lies to anybody navigating by them.
  return (
    <nav aria-label="Catalog" className="space-y-6 text-sm">
      {/* Above the games rather than inside them: these are the two things a
          visitor does *to* the catalog, and the list below is long enough that
          anything under it is out of sight on most screens. Both are also in
          the header — that is what a phone gets, where this rail is hidden. */}
      <ul className="border-b border-line pb-4">
        <li>
          <Link
            href="/add-server"
            className="group flex cursor-pointer items-center gap-2.5 rounded px-2 py-1.5 transition-colors hover:bg-surface-2"
          >
            <span className="flex size-7 shrink-0 items-center justify-center rounded bg-brand/15 text-brand">
              <Icon.plus />
            </span>
            <span className="min-w-0 flex-1 truncate text-muted transition-colors group-hover:text-fg">
              Add server
            </span>
          </Link>
        </li>
        <li>
          {/* Shown signed out too: the page it leads to is the sign-in form, and
              a menu entry that appears only once you are in is one nobody
              signed out ever discovers. */}
          <Link
            href="/favorites"
            className="group flex cursor-pointer items-center gap-2.5 rounded px-2 py-1.5 transition-colors hover:bg-surface-2"
          >
            <span className="flex size-7 shrink-0 items-center justify-center rounded bg-accent/15 text-accent">
              <Icon.star />
            </span>
            <span className="min-w-0 flex-1 truncate text-muted transition-colors group-hover:text-fg">
              Favorite servers
            </span>
          </Link>
        </li>
      </ul>

      <div>
        <p className="font-display mb-2 px-2 text-[11px] font-bold tracking-widest text-subtle uppercase">
          Games
        </p>
        <ul>
          {withServers.map((game) => (
            <li key={game.slug}>
              <Link
                href={`/games/${game.slug}`}
                // The menu lists every game and sits on every page, so warming
                // it costs four requests per game on arrival — for one link the
                // visitor might click. The page they land on is served from the
                // shell cache anyway; the cold click is not worth the crowd.
                prefetch={false}
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
                  prefetch={false}
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
