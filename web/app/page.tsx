import Link from 'next/link'
import { cacheLife } from 'next/cache'
import type { Game } from '@/lib/api'
import { getGames } from '@/lib/data'

export default async function HomePage() {
  'use cache'
  cacheLife('minutes')

  const games = await getGames()
  const totals = games.reduce(
    (sum, game) => ({
      servers: sum.servers + game.counters.servers,
      players: sum.players + game.counters.players_online,
    }),
    { servers: 0, players: 0 },
  )

  return (
    <div className="space-y-10">
      <section className="space-y-3">
        <h1 className="font-display text-3xl font-black tracking-tight sm:text-4xl">
          Every server, <span className="text-brand">actually online</span>
        </h1>
        <p className="max-w-2xl text-muted">
          We query each server ourselves every few minutes — player counts, uptime and history come
          from our own checks, not from what an owner typed into a form.
        </p>
        <dl className="flex gap-8 pt-2">
          <Stat label="Servers tracked" value={totals.servers} />
          <Stat label="Players right now" value={totals.players} />
          <Stat label="Games" value={games.length} />
        </dl>
      </section>

      <section>
        <h2 className="mb-4 font-display text-sm font-bold tracking-wide text-muted uppercase">
          Browse by game
        </h2>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {games.map((game) => (
            <GameCard key={game.slug} game={game} />
          ))}
        </div>
      </section>
    </div>
  )
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div>
      <dd className="tabular text-2xl font-medium">{value.toLocaleString('en-US')}</dd>
      <dt className="text-xs text-subtle">{label}</dt>
    </div>
  )
}

function GameCard({ game }: { game: Game }) {
  return (
    <Link
      href={`/games/${game.slug}`}
      className="group cursor-pointer rounded-lg border border-line bg-surface p-4 transition-colors hover:border-line-strong hover:bg-surface-2"
    >
      <div className="flex items-center gap-3">
        <span
          aria-hidden
          className="size-9 rounded-md"
          style={{ backgroundColor: game.accent_color ?? 'var(--color-line-strong)' }}
        />
        <div>
          <h3 className="font-display font-bold transition-colors group-hover:text-brand">
            {game.name}
          </h3>
          <p className="tabular text-xs text-subtle">
            {game.counters.servers.toLocaleString('en-US')} servers
          </p>
        </div>
      </div>
      <p className="tabular mt-3 text-sm">
        {game.counters.players_online.toLocaleString('en-US')}{' '}
        <span className="text-subtle">players online</span>
      </p>
    </Link>
  )
}
