import type { Metadata } from 'next'
import Link from 'next/link'
import { Suspense } from 'react'
import { GameGrid } from '@/components/game-grid'
import { LiveStats } from '@/components/live-stats'
import { getGamesWithCounters } from '@/lib/data'
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
 *
 * The heading is static and the numbers are not: every card here carries a
 * server count and a players-online figure, and those are read when the request
 * arrives rather than served from the cached copy the rail uses.
 */
export default function GamesPage() {
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

        <Suspense fallback={<StatsSkeleton />}>
          <Stats />
        </Suspense>
      </section>

      <section>
        <Suspense fallback={<GridSkeleton />}>
          <Grid />
        </Suspense>
      </section>
    </div>
  )
}

async function Stats() {
  return <LiveStats games={await getGamesWithCounters()} />
}

async function Grid() {
  const games = await getGamesWithCounters()

  // Games with servers first: the grid is the navigation, and 24 empty cards
  // ahead of the three that have anything would bury them.
  const ordered = [...games].sort(
    (a, b) =>
      Number(b.counters.servers > 0) - Number(a.counters.servers > 0) ||
      b.counters.players_online - a.counters.players_online ||
      b.counters.servers - a.counters.servers,
  )

  return <GameGrid games={ordered} />
}

function StatsSkeleton() {
  return (
    <div className="grid gap-3 sm:grid-cols-3" aria-hidden>
      {Array.from({ length: 3 }, (_, index) => (
        <div key={index} className="h-[5.5rem] animate-pulse rounded-xl bg-surface" />
      ))}
    </div>
  )
}

function GridSkeleton() {
  return (
    <div className="space-y-4" aria-hidden>
      {/* The grid ships with a filter box above it — see GameGrid. */}
      <div className="h-14 animate-pulse rounded-xl bg-surface" />
      <ul className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
        {Array.from({ length: 10 }, (_, index) => (
          <li key={index} className="h-44 animate-pulse rounded-xl bg-surface" />
        ))}
      </ul>
    </div>
  )
}
