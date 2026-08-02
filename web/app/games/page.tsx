import type { Metadata } from 'next'
import { cacheLife } from 'next/cache'
import Link from 'next/link'
import { GameGrid } from '@/components/game-grid'
import { LiveStats } from '@/components/live-stats'
import { getGames } from '@/lib/data'
import { canonical } from '@/lib/seo'

export const metadata: Metadata = {
  title: 'All games',
  description:
    'Every game with servers on LobbyHub — Rust, Minecraft, Counter-Strike 2, DayZ and more. Server counts and players online, measured by our own checks.',
  ...canonical('/games'),
}

/**
 * The catalog, which used to be the home page.
 *
 * The home page now has to explain the product to somebody who arrived from a
 * search engine; this is the page for somebody who already knows what they want
 * and is picking a game. Splitting them lets each be good at one job — and
 * gives the grid a stable URL to be linked to from the rail, the footer and
 * every "browse" button on the site.
 */
export default async function GamesPage() {
  'use cache'
  cacheLife('minutes')

  const games = await getGames()

  // Games with servers first: the grid is the navigation, and 24 empty cards
  // ahead of the three that have anything would bury them.
  const ordered = [...games].sort(
    (a, b) =>
      Number(b.counters.servers > 0) - Number(a.counters.servers > 0) ||
      b.counters.players_online - a.counters.players_online ||
      b.counters.servers - a.counters.servers,
  )

  return (
    <div className="space-y-8">
      <section className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,36rem)]">
        <div>
          <nav aria-label="Breadcrumb" className="mb-2 text-xs text-subtle">
            <Link href="/" className="hover:text-fg">
              LobbyHub
            </Link>
            <span className="mx-1.5">/</span>
            <span className="text-muted">Games</span>
          </nav>
          <h1 className="font-display text-3xl leading-tight font-black tracking-tight uppercase sm:text-4xl">
            Browse servers by <span className="text-brand">game</span>
          </h1>
          <p className="mt-3 max-w-xl text-muted">
            We query every server ourselves, every few minutes. Player counts, uptime and history
            come from our own checks — not from what an owner typed into a form.
          </p>
        </div>

        <LiveStats games={games} />
      </section>

      <section>
        <GameGrid games={ordered} />
      </section>
    </div>
  )
}
