import Link from 'next/link'
import { getGames } from '@/lib/data'
import { Icon } from './icons'

/**
 * Persistent game navigation.
 *
 * Only games with servers: the catalog has 27 games and only a few have
 * anything in them yet, and a rail of dead ends wastes the most valuable strip
 * of the layout. The empty ones stay reachable from the catalog itself.
 */
export async function Sidebar() {
  const games = await getGames()
  const withServers = games.filter((game) => game.counters.servers > 0)

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
          {/* First, and pointing at the catalog: the rail below lists only the
              games that have servers, so this is the one way to the other 24.
              A neutral tile because it is a destination, not an action. */}
          <Link
            href="/games"
            className="group flex cursor-pointer items-center gap-2.5 rounded px-2 py-1.5 transition-colors hover:bg-surface-2"
          >
            <span className="flex size-7 shrink-0 items-center justify-center rounded bg-line text-muted">
              <Icon.boxes />
            </span>
            <span className="min-w-0 flex-1 truncate text-muted transition-colors group-hover:text-fg">
              All games
            </span>
          </Link>
        </li>
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
          {/* Prefetched like the rest of the rail. This one reads the session,
              so its shell carries session output and Next caches it per session
              on the client — still one shell, not one per visit. */}
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
                // The menu lists every game and sits on every page. Warming it
                // used to be four requests per game; they all point at the one
                // /games/[game] route, so under Partial Prefetching the rail
                // costs a single shell — see next.config.ts.
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

      {/* Last, and quiet: legal pages are what people look for on the rare day
          they need them, not something to spend a tile on beside the two actions
          at the top. Both are in the footer too — that is what a phone gets,
          where this rail is hidden. */}
      <div className="space-y-0.5 border-t border-line pt-4">
        <Link
          href="/terms"
          prefetch={false}
          className="block cursor-pointer px-2 py-1 text-xs text-subtle transition-colors hover:text-muted"
        >
          Terms of use
        </Link>
        <Link
          href="/privacy"
          prefetch={false}
          className="block cursor-pointer px-2 py-1 text-xs text-subtle transition-colors hover:text-muted"
        >
          Privacy
        </Link>
      </div>
    </nav>
  )
}

function Thumb({ game }: { game: Awaited<ReturnType<typeof getGames>>[number] }) {
  // The thumbnail if there is one, the list card if not. At 28px a 460×215 card
  // is mostly a crop of somebody's sky, which is why the thumbnail exists.
  const thumb = game.icon ?? game.cover

  return thumb ? (
    <img
      src={thumb}
      alt=""
      width={28}
      height={28}
      loading="lazy"
      className="size-7 shrink-0 rounded object-cover"
    />
  ) : (
    <span
      aria-hidden
      className="size-7 shrink-0 rounded"
      style={{ backgroundColor: game.accent_color ?? 'var(--color-line-strong)' }}
    />
  )
}
