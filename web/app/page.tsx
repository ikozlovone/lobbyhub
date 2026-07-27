import { cacheLife } from 'next/cache'
import { GameGrid } from '@/components/game-grid'
import { LiveStats } from '@/components/live-stats'
import { getGames } from '@/lib/data'

export default async function HomePage() {
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
          <h1 className="font-display text-3xl leading-tight font-black tracking-tight uppercase sm:text-4xl">
            Monitoring and management of{' '}
            <span className="text-brand">game servers</span>
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
