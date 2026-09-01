import type { Metadata } from 'next'
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { GamePlayersChart } from '@/components/game-players-chart'
import { TrendTable } from '@/components/charts/trend-table'
import { RelativeTime } from '@/components/relative-time'
import { count } from '@/lib/chart'
import { getGame, getGamePlayers, getGameTrend } from '@/lib/data'
import { PUBLIC_API_URL } from '@/lib/api'
import { canonical, notFoundMetadata } from '@/lib/seo'

type Props = { params: Promise<{ game: string }> }

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { game: slug } = await params
  const game = await getGame(slug)

  if (!game || game.steam.synced_at === null) return notFoundMetadata()

  return {
    title: `${game.name} player count — live Steam players and history`,
    description: `How many people are playing ${game.name} right now: live Steam player count, today's peak and recorded history, next to the ${game.name} servers LobbyHub monitors.`,
    ...canonical(`/charts/${game.slug}`),
  }
}

/**
 * One game's player count, which is the page people actually search for.
 *
 * "How many people play X" is a question with a number for an answer, so the
 * number leads and everything else on the page is there to make it trustworthy:
 * where it came from, when it was read, what it does and does not include, and
 * the shape of it over time.
 *
 * A game we have never measured is not published. Not because the page would
 * break — it would render the identity and an empty chart — but because that is
 * a page with no answer on it, and inviting a crawler to one is how a catalog
 * domain gets marked down.
 */
export default async function GameChartPage({ params }: Props) {
  const { game: slug } = await params
  const game = await getGame(slug)

  if (!game || game.steam.synced_at === null) notFound()

  const [history, trend] = await Promise.all([getGamePlayers(slug, '24h'), getGameTrend(slug)])
  const { steam, counters } = game

  return (
    <div className="space-y-6">
      <header>
        <nav aria-label="Breadcrumb" className="mb-2 text-xs text-subtle">
          <Link href="/" className="hover:text-fg">
            LobbyHub
          </Link>
          <span className="mx-1.5">/</span>
          <Link href="/charts" className="hover:text-fg">
            Charts
          </Link>
          <span className="mx-1.5">/</span>
          <span className="text-muted">{game.name}</span>
        </nav>

        <div className="flex flex-wrap items-center gap-3">
          {game.icon && (
            <img
              src={game.icon}
              alt=""
              width={40}
              height={40}
              className="size-10 rounded-md border border-line object-cover"
            />
          )}
          <h1 className="font-display text-3xl leading-tight font-black tracking-tight uppercase sm:text-4xl">
            {game.name} <span className="text-brand">player count</span>
          </h1>
        </div>

        <p className="mt-3 max-w-[68ch] text-muted">
          {count(steam.players_online)} people are playing {game.name} on Steam right now
          {steam.chart_rank ? `, which puts it #${steam.chart_rank} on Steam's own chart` : ''}.
          Read from Valve every ten minutes
          {steam.synced_at && (
            <>
              , last <RelativeTime at={steam.synced_at} />
            </>
          )}
          .
        </p>
      </header>

      {/*
        The readings sit in the chart's own header rather than in a strip of
        hero tiles above it: they are what the plot is measuring, and reading
        them together is the point. `dl` because that is what they are — a
        label and its value, four times.
      */}
      <section className="rounded-lg border border-line bg-surface">
        <dl className="grid grid-cols-2 divide-x divide-y divide-line border-b border-line sm:grid-cols-4 sm:divide-y-0">
          <Reading label="Playing now" value={count(steam.players_online)} accent />
          <Reading
            label="Peak today"
            value={steam.players_peak > 0 ? count(steam.players_peak) : '—'}
            note="as Steam reports it"
          />
          <Reading
            label="On Steam"
            value={steam.chart_rank ? `#${steam.chart_rank}` : 'Below top 100'}
            note={steam.chart_rank ? 'in the official chart' : 'of the official chart'}
          />
          <Reading
            label="Servers we monitor"
            value={counters.servers > 0 ? count(counters.servers) : '—'}
            note={counters.servers > 0 ? `${count(counters.players_online)} players on them` : 'none listed yet'}
          />
        </dl>

        <GamePlayersChart
          slug={game.slug}
          name={game.name}
          initial={history}
          apiUrl={PUBLIC_API_URL}
          framed={false}
        />
      </section>

      {/* Only once a month has been rolled up. A table with a single row and
          nothing to compare it against is the shape of an answer without one
          in it. */}
      {trend && trend.months.length > 0 && <TrendTable trend={trend} name={game.name} />}

      <section className="grid gap-6 md:grid-cols-2">
        <div className="max-w-[68ch] space-y-3 text-sm text-muted">
          <h2 className="font-display text-base font-black tracking-tight text-fg uppercase">
            What this number counts
          </h2>
          <p>
            Everybody with {game.name} open on Steam — single-player, matchmaking, official servers
            and community ones alike. It is Valve&rsquo;s own figure, not an estimate: LobbyHub
            reads it every ten minutes and keeps the samples, which is what the graph above is drawn
            from.
          </p>
          <p>
            The peak is Steam&rsquo;s 24-hour high rather than the tallest point we happened to
            sample, so it can sit above everything on the graph.
          </p>
          <p>
            Hours played is ours rather than Valve&rsquo;s — they publish no playtime figure at
            all. Each reading stands for the ten minutes until the next one, so a month of them
            adds up to player-hours. It counts hours we observed, which means a month we started
            recording halfway through says half.
          </p>
        </div>

        <div className="max-w-[68ch] space-y-3 text-sm text-muted">
          <h2 className="font-display text-base font-black tracking-tight text-fg uppercase">
            {game.name} servers
          </h2>
          {counters.servers > 0 ? (
            <>
              <p>
                We monitor{' '}
                <span className="text-fg">{count(counters.servers)}</span> {game.name}{' '}
                {counters.servers === 1 ? 'server' : 'servers'},{' '}
                {count(counters.servers_online)} of them answering right now with{' '}
                {count(counters.players_online)} players between them. That is a different count
                from the one above: it is measured by querying each machine rather than asked of
                Steam.
              </p>
              <Link
                href={`/games/${game.slug}`}
                className="inline-block rounded border border-line px-3 py-1.5 text-sm text-muted transition-colors hover:border-line-strong hover:text-fg"
              >
                Browse {game.name} servers
              </Link>
            </>
          ) : (
            <>
              <p>
                No {game.name} servers are listed here yet. Player counts and server lists are
                separate things — plenty of games have a busy chart and no dedicated servers to run
                at all.
              </p>
              <Link
                href={`/games/${game.slug}/add-server`}
                className="inline-block rounded border border-line px-3 py-1.5 text-sm text-muted transition-colors hover:border-line-strong hover:text-fg"
              >
                Add a {game.name} server
              </Link>
            </>
          )}
        </div>
      </section>

      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{
          __html: JSON.stringify({
            '@context': 'https://schema.org',
            '@type': 'FAQPage',
            mainEntity: [
              {
                '@type': 'Question',
                name: `How many people are playing ${game.name}?`,
                acceptedAnswer: {
                  '@type': 'Answer',
                  text: `${count(steam.players_online)} people are playing ${game.name} on Steam at the last reading${
                    steam.players_peak > 0
                      ? `, and ${count(steam.players_peak)} were playing at today's peak`
                      : ''
                  }. LobbyHub reads Valve's own player count every ten minutes.`,
                },
              },
              {
                '@type': 'Question',
                name: `Is the ${game.name} player count the same as players on ${game.name} servers?`,
                acceptedAnswer: {
                  '@type': 'Answer',
                  text: `No. The player count is everybody in the game anywhere on Steam. The server figure counts players on the ${game.name} servers LobbyHub queries directly, which is ${count(
                    counters.players_online,
                  )} across ${count(counters.servers)} servers.`,
                },
              },
            ],
          }),
        }}
      />
    </div>
  )
}

function Reading({
  label,
  value,
  note,
  accent,
}: {
  label: string
  value: string
  note?: string
  accent?: boolean
}) {
  return (
    <div className="px-4 py-3">
      <dt className="text-xs text-subtle">{label}</dt>
      <dd
        className={`tabular font-display mt-0.5 text-xl font-black tracking-tight ${
          accent ? 'text-brand' : 'text-fg'
        }`}
      >
        {value}
      </dd>
      {note && <p className="mt-0.5 text-xs text-subtle">{note}</p>}
    </div>
  )
}
