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
}: {
  game: GameDetail
  heading: string
  /** The last breadcrumb step. Plain text: it is the page you are already on. */
  crumb: string
}) {
  return (
    <section className="relative overflow-hidden rounded-2xl border border-line bg-surface">
      {game.cover && (
        /* Steam key art is 460×215 and is being stretched well past that here.
           The wash over it is what makes text on top readable, and it hides the
           upscaling at the same time. */
        <img src={game.cover} alt="" aria-hidden className="absolute inset-0 size-full object-cover" />
      )}
      <div
        aria-hidden
        className="absolute inset-0 bg-gradient-to-r from-bg via-bg/85 to-bg/35"
        style={game.accent_color ? { backgroundColor: `${game.accent_color}20` } : undefined}
      />

      <div className="relative flex flex-col gap-6 p-5 sm:p-6">
        <nav aria-label="Breadcrumb" className="text-xs text-subtle">
          <Link href="/" className="transition-colors hover:text-fg">
            LobbyHub
          </Link>
          <span className="mx-1.5">/</span>
          <Link href={`/games/${game.slug}`} className="transition-colors hover:text-fg">
            {game.name}
          </Link>
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
      </div>
    </section>
  )
}
