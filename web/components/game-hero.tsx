import Link from 'next/link'
import type { GameDetail } from '@/lib/api'
import { GameCounters } from './game-counters'
import { Icon } from './icons'
import { ShareButton } from './share-button'

/**
 * The banner every listing under a game opens with.
 *
 * It answers three questions before anything else loads: which game this is,
 * how big it is, and where the visitor is inside it — the breadcrumb is the
 * only way back out of a facet page that was arrived at from search.
 */
export function GameHero({
  game,
  heading,
  crumb,
  atGameRoot,
}: {
  game: GameDetail
  heading: string
  /** The last breadcrumb step. Plain text: it is the page you are already on. */
  crumb: string
  /**
   * Whether this is the game's own listing rather than a facet under it. The
   * breadcrumb's game step then points at the page it is drawn on, so it is
   * text here too — a link back to where you already are is noise to a reader
   * and a full prefetch of the open route to the router.
   */
  atGameRoot: boolean
}) {
  return (
    <section className="relative overflow-hidden rounded-2xl border border-line bg-surface">
      {(game.hero ?? game.cover) && (
        /* The banner if one has been uploaded, otherwise the list card — which
           is Steam key art at 460×215, stretched well past that here. The wash
           over it is what makes text on top readable, and it hides the
           upscaling at the same time. A game with its own banner has nothing to
           hide, but the wash stays: the text still has to be legible. */
        <img
          src={game.hero ?? game.cover ?? ''}
          alt=""
          aria-hidden
          className="absolute inset-0 size-full object-cover"
        />
      )}
      <div
        aria-hidden
        className="absolute inset-0 bg-gradient-to-r from-bg via-bg/85 to-bg/35"
        style={game.accent_color ? { backgroundColor: `${game.accent_color}20` } : undefined}
      />

      <div className="relative flex flex-col gap-6 p-5 sm:p-6">
        {/* Both of these prefetch, and cost nothing extra to: /games/[game] is
            already warmed by the rail on every page, and the two shells are
            shared with every other link pointing at them — see next.config.ts.
            The game step is still text at the game root, which is about not
            linking a reader to the page they are on rather than about cost. */}
        <nav aria-label="Breadcrumb" className="text-xs text-subtle">
          <Link href="/" className="transition-colors hover:text-fg">
            LobbyHub
          </Link>
          <span className="mx-1.5">/</span>
          {atGameRoot ? (
            <span className="text-muted">{game.name}</span>
          ) : (
            <Link href={`/games/${game.slug}`} className="transition-colors hover:text-fg">
              {game.name}
            </Link>
          )}
          <span className="mx-1.5">/</span>
          <span className="text-muted">{crumb}</span>
        </nav>

        <div className="flex flex-wrap items-end justify-between gap-4">
          <div className="flex min-w-0 items-center gap-4">
            {(game.icon ?? game.cover) && (
              <img
                src={game.icon ?? game.cover ?? ''}
                alt=""
                aria-hidden
                className="size-14 shrink-0 rounded-xl border border-line object-cover sm:size-16"
              />
            )}

            <div className="min-w-0">
              <h1 className="font-display truncate text-2xl font-black tracking-tight sm:text-3xl">
                {heading}
              </h1>
              <div className="mt-1.5">
                <GameCounters game={game.slug} initial={game.counters} />
              </div>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <Link
              href={`/games/${game.slug}/add-server`}
              className="flex cursor-pointer items-center gap-2 rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-brand-strong"
            >
              <Icon.plus />
              Add server
            </Link>
            <ShareButton title={heading} />
          </div>
        </div>

        {game.links && game.links.length > 0 && <GameLinks links={game.links} name={game.name} />}
      </div>
    </section>
  )
}

/**
 * Where the game lives outside this site.
 *
 * Up here rather than in the footer because that is the question they answer —
 * "what is this game, and where do I get it" — and somebody who needs the docs
 * needs them before they have scrolled a thousand servers, not after.
 *
 * `nofollow` on all of them: these are editorial links to somebody else's site,
 * some of them commercial, and a listing with a few thousand game pages is
 * exactly the shape of thing that starts passing rank around by accident.
 */
function GameLinks({ links, name }: { links: NonNullable<GameDetail['links']>; name: string }) {
  return (
    <nav aria-label={`${name} links`} className="flex flex-wrap gap-2 border-t border-line pt-4">
      {links.map((link) => (
        <a
          key={link.url}
          href={link.url}
          target="_blank"
          rel="nofollow noopener noreferrer"
          className="flex cursor-pointer items-center gap-1.5 rounded-lg border border-line bg-bg/60 px-3 py-1.5 text-sm text-muted transition-colors hover:border-line-strong hover:text-fg"
        >
          <Icon.link className="size-3.5 text-subtle" />
          {link.name}
        </a>
      ))}
    </nav>
  )
}
