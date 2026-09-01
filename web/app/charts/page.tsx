import type { Metadata } from 'next'
import Link from 'next/link'
import { Suspense } from 'react'
import { PlayersSpark } from '@/components/charts/players-spark'
import { RelativeTime } from '@/components/relative-time'
import { count } from '@/lib/chart'
import { getCharts, getGamePlayers } from '@/lib/data'
import { canonical } from '@/lib/seo'

const SITE = process.env.NEXT_PUBLIC_SITE_URL ?? 'http://localhost:3000'

export const metadata: Metadata = {
  title: 'Steam player counts — live charts by game',
  description:
    'How many people are playing each game on Steam right now, sampled every ten minutes: live player counts, daily peaks and history, next to the servers we monitor for each game.',
  ...canonical('/charts'),
}

/**
 * The ranking, and the page is built as one.
 *
 * Everything here is a position in a list — so the list is the design, rather
 * than a strip of hero metrics with a table filed underneath it. One plot sits
 * above it, the leader's, because the second question after "who is on top" is
 * "what did today look like", and that answer has a shape rather than a number.
 *
 * The numbers are Steam's own concurrent player counts, which is a different
 * measurement from everything else on this site: the rest of LobbyHub counts
 * players our monitor found on servers it queried. A game can lead this chart
 * with no dedicated servers in existence. Saying so is most of the copy, and it
 * is also the only reason this page is worth reading next to Steam's own.
 */
export default function ChartsPage() {
  return (
    <div className="space-y-8">
      <header>
        <nav aria-label="Breadcrumb" className="mb-2 text-xs text-subtle">
          <Link href="/" className="hover:text-fg">
            LobbyHub
          </Link>
          <span className="mx-1.5">/</span>
          <span className="text-muted">Charts</span>
        </nav>
        <h1 className="font-display text-3xl leading-tight font-black tracking-tight uppercase sm:text-4xl">
          Steam <span className="text-brand">player counts</span>
        </h1>
        <p className="mt-3 max-w-[68ch] text-muted">
          Every game we track, ranked by how many people are in it on Steam right now. Counts come
          from Valve&rsquo;s own endpoints and are sampled every ten minutes — the same numbers
          Steam publishes, kept as a history you can look back through.
        </p>
      </header>

      <Suspense fallback={<Skeleton />}>
        <Ranking />
      </Suspense>

      <Explainer />
    </div>
  )
}

/**
 * Everything measured, in one boundary.
 *
 * The header and the prose above and below it are the same on every request,
 * so they are the static shell and this streams into it — which is what keeps
 * the page painting immediately while forty-five live counts and a day of
 * samples are still being read.
 */
async function Ranking() {
  const chart = await getCharts()
  const rows = chart?.data ?? []
  const leader = rows[0]
  const leaderHistory = leader ? await getGamePlayers(leader.slug, '24h') : null
  const peak = rows.reduce((most, row) => Math.max(most, row.players), 0)

  if (rows.length === 0) return <Waiting />

  return (
    <>
          {leader && (
            <section className="rounded-lg border border-line bg-surface">
              <div className="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2 border-b border-line px-4 py-3">
                <div className="flex items-baseline gap-2">
                  <span className="font-display text-xs font-bold tracking-widest text-subtle uppercase">
                    Most played
                  </span>
                  <Link
                    href={`/charts/${leader.slug}`}
                    className="font-display text-lg font-black tracking-tight text-fg hover:text-brand"
                  >
                    {leader.name}
                  </Link>
                </div>
                <p className="text-sm text-subtle">
                  <span className="tabular text-xl font-bold text-fg">{count(leader.players)}</span>{' '}
                  playing now · peak today{' '}
                  <span className="tabular text-muted">{count(leader.peak)}</span>
                </p>
              </div>
              {leaderHistory && leaderHistory.points.length > 1 ? (
                <div className="px-4 pt-4 pb-3">
                  <PlayersSpark points={leaderHistory.points} label={`${leader.name} players`} />
                </div>
              ) : (
                /* No plot yet, so no room reserved for one: an empty panel the
                   height of a chart reads as something that failed to load. */
                <p className="px-4 py-2.5 text-xs text-subtle">
                  The shape of the day appears here once there are samples to draw — recording
                  starts with the first reading.
                </p>
              )}
            </section>
          )}

          <section aria-labelledby="ranking">
            <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
              <h2 id="ranking" className="font-display text-lg font-black tracking-tight uppercase">
                All {chart?.meta.games ?? rows.length} games
              </h2>
              {chart?.meta.synced_at && (
                <p className="text-xs text-subtle">
                  Updated <RelativeTime at={chart.meta.synced_at} /> ·{' '}
                  <span className="tabular text-muted">{count(chart.meta.players)}</span> players
                  across them
                  {chart.meta.charted > 0 && (
                    <>
                      {' '}
                      · <span className="text-muted">{chart.meta.charted}</span> in Steam&rsquo;s top
                      100
                    </>
                  )}
                </p>
              )}
            </div>

            <div className="overflow-x-auto rounded-lg border border-line bg-surface">
              {/* No min-width: the columns are dropped by breakpoint above, so
                  forcing a width wide enough for all of them pushed the one
                  number the page exists for off the side of a phone. */}
              <table className="w-full text-sm">
                <caption className="sr-only">
                  Games ranked by concurrent players on Steam, with today&rsquo;s peak and a
                  link to the servers LobbyHub monitors for each.
                </caption>
                <thead>
                  <tr className="border-b border-line text-left text-xs text-subtle">
                    <th scope="col" className="py-2.5 pr-2 pl-4 font-normal">
                      #
                    </th>
                    <th scope="col" className="py-2.5 pr-4 font-normal">
                      Game
                    </th>
                    <th scope="col" className="py-2.5 pr-4 text-right font-normal">
                      Playing now
                    </th>
                    <th scope="col" className="hidden py-2.5 pr-4 text-right font-normal md:table-cell">
                      Peak today
                    </th>
                    {/* Nobody publishes this: Valve's charts carry a rank, a
                        count and a peak, and no playtime. It is our own
                        samples added up. */}
                    <th
                      scope="col"
                      className="hidden py-2.5 pr-4 text-right font-normal whitespace-nowrap xl:table-cell"
                    >
                      Hours played
                    </th>
                    <th
                      scope="col"
                      className="hidden py-2.5 pr-4 text-right font-normal whitespace-nowrap lg:table-cell"
                    >
                      On Steam
                    </th>
                    {/* Ahead of both columns above it, and kept from `sm`
                        rather than `lg`. Those are numbers; this one is a door
                        — the only cell in the row that leads to the rest of
                        the site. On a phone it is under the game's name
                        instead, where it costs the name nothing. */}
                    <th scope="col" className="hidden py-2.5 pr-4 text-right font-normal sm:table-cell">
                      Servers
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr
                      key={row.slug}
                      className="group border-b border-line/60 last:border-0 hover:bg-surface-2"
                    >
                      <td className="py-2.5 pr-2 pl-4 align-middle">
                        <span className="tabular text-subtle">{row.position}</span>
                      </td>
                      {/* `w-full max-w-0` is the table cell's version of "take
                          the slack, then truncate": without the zero max width
                          a cell never shrinks below its own text, and a long
                          game name pushed the one number this page exists for
                          off the side of a phone. */}
                      <td className="w-full max-w-0 py-2.5 pr-4 align-middle">
                        <Link
                          href={`/charts/${row.slug}`}
                          className="flex min-w-0 items-center gap-2.5 font-medium text-fg group-hover:text-brand"
                        >
                          {row.icon ? (
                            <img
                              src={row.icon}
                              alt=""
                              width={24}
                              height={24}
                              loading="lazy"
                              className="size-6 shrink-0 rounded border border-line object-cover"
                            />
                          ) : (
                            <span
                              aria-hidden
                              className="size-6 shrink-0 rounded"
                              style={{
                                backgroundColor: row.accent_color ?? 'var(--color-line-strong)',
                              }}
                            />
                          )}
                          <span className="truncate">{row.name}</span>
                        </Link>
                        {row.servers > 0 && (
                          <Link
                            href={`/games/${row.slug}`}
                            className="mt-0.5 ml-[2.1rem] block text-xs text-subtle underline-offset-4 hover:text-fg hover:underline sm:hidden"
                          >
                            {count(row.servers)} servers
                          </Link>
                        )}
                      </td>
                      {/* The bar is the ranking made visible: a table of numbers
                          says who is first, a row of lengths says by how much,
                          and the gap between first and fifth is the story. */}
                      <td className="py-2.5 pr-4 align-middle">
                        <div className="flex items-center justify-end gap-3">
                          {/* One track, one scale: the bars are read against
                              each other, and the distance between the top of
                              this chart and the middle of it is the fact a
                              column of numbers hides. */}
                          <span
                            aria-hidden
                            className="hidden h-1.5 w-32 overflow-hidden rounded-full bg-line sm:block"
                          >
                            <span
                              className="block h-full rounded-full bg-brand/70"
                              style={{
                                width: `${Math.max(1.5, (row.players / Math.max(peak, 1)) * 100)}%`,
                              }}
                            />
                          </span>
                          <span className="tabular w-20 text-right font-semibold text-fg sm:w-24">
                            {count(row.players)}
                          </span>
                        </div>
                      </td>
                      <td className="tabular hidden py-2.5 pr-4 text-right align-middle text-muted md:table-cell">
                        {row.peak > 0 ? count(row.peak) : <span className="text-subtle">—</span>}
                      </td>
                      <td className="tabular hidden py-2.5 pr-4 text-right align-middle text-muted xl:table-cell">
                        {row.hours !== null && row.hours > 0 ? (
                          count(row.hours)
                        ) : (
                          <span className="text-subtle">—</span>
                        )}
                      </td>
                      <td className="hidden py-2.5 pr-4 text-right align-middle lg:table-cell">
                        {row.steam_rank ? (
                          <span className="tabular rounded bg-accent/15 px-1.5 py-0.5 text-xs text-accent">
                            #{row.steam_rank}
                          </span>
                        ) : (
                          <span className="text-xs text-subtle">below top 100</span>
                        )}
                      </td>
                      <td className="hidden py-2.5 pr-4 text-right align-middle sm:table-cell">
                        {row.servers > 0 ? (
                          <Link
                            href={`/games/${row.slug}`}
                            className="tabular whitespace-nowrap text-muted underline-offset-4 hover:text-fg hover:underline"
                          >
                            {count(row.servers)}
                          </Link>
                        ) : (
                          /* A quiet dash rather than an invitation to add a
                             server: repeated down forty rows that stops being
                             a call to action and becomes wallpaper. The game's
                             own page makes the offer once, where it is about
                             that game. */
                          <span className="text-subtle">—</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
      </section>

      <script
        type="application/ld+json"
        // Ranked lists and answered questions are two things search engines
        // read structurally; this page is both, and neither is invented for
        // the markup — the list is the page and the answers are its copy.
        dangerouslySetInnerHTML={{
          __html: JSON.stringify([
            {
              '@context': 'https://schema.org',
              '@type': 'ItemList',
              name: 'Steam player counts by game',
              url: `${SITE}/charts`,
              numberOfItems: rows.length,
              itemListElement: rows.slice(0, 20).map((row) => ({
                '@type': 'ListItem',
                position: row.position,
                name: row.name,
                url: `${SITE}/charts/${row.slug}`,
              })),
            },
            {
              '@context': 'https://schema.org',
              '@type': 'FAQPage',
              mainEntity: FAQ.map((item) => ({
                '@type': 'Question',
                name: item.question,
                acceptedAnswer: { '@type': 'Answer', text: item.answer },
              })),
            },
          ]),
        }}
      />
    </>
  )
}

/** The shape of the ranking, so the page does not jump when it arrives. */
function Skeleton() {
  return (
    <div className="space-y-8" aria-hidden>
      <div className="h-48 animate-pulse rounded-lg bg-surface" />
      <div className="space-y-2">
        <div className="h-6 w-48 animate-pulse rounded bg-surface" />
        <div className="h-[32rem] animate-pulse rounded-lg bg-surface" />
      </div>
    </div>
  )
}

/**
 * Before the first sample.
 *
 * A ranking with nothing in it is the one state where this page cannot do its
 * job, so it says which piece is missing rather than rendering an empty table
 * and letting it read as a catalog nobody plays.
 */
function Waiting() {
  return (
    <section className="rounded-lg border border-line bg-surface px-6 py-12 text-center">
      <h2 className="font-display text-lg font-black tracking-tight">No counts recorded yet</h2>
      <p className="mx-auto mt-2 max-w-[52ch] text-sm text-muted">
        Player counts are read from Steam every ten minutes. This page fills in with the first
        sample and the charts follow it a few hours later.
      </p>
      <Link
        href="/games"
        className="mt-4 inline-block rounded border border-line px-3 py-1.5 text-sm text-muted transition-colors hover:border-line-strong hover:text-fg"
      >
        Browse games and their servers
      </Link>
    </section>
  )
}

const FAQ = [
  {
    question: 'Where do these player counts come from?',
    answer:
      "Valve's own public endpoints — the official Steam charts service and the per-game player count API. LobbyHub reads them every ten minutes and keeps the samples, which is what the graphs are drawn from.",
  },
  {
    question: 'Why is this different from the players shown on a game page?',
    answer:
      'A game page counts players our monitor found on the servers it queried. This page counts everybody in the game anywhere on Steam: single-player, matchmaking, official servers and community ones alike. The two answer different questions, and for a game without dedicated servers the first is zero while the second is large.',
  },
  {
    question: 'How often does it update?',
    answer:
      'Every ten minutes. The peak column is the highest concurrent count Steam published for the game in the last 24 hours.',
  },
  {
    question: 'Why are some games missing?',
    answer:
      'Only games in the LobbyHub catalog with a Steam app id appear here. Games without one — Minecraft, for instance — have no Steam player count to read.',
  },
]

/**
 * The part of the page that is worth reading rather than scanning.
 *
 * Written because the distinction it draws is the whole reason this exists next
 * to Steam's own chart: a server list that also knows how many people are in
 * the game can say something neither number says alone.
 */
function Explainer() {
  return (
    <section className="grid gap-6 md:grid-cols-2">
      <div className="max-w-[68ch] space-y-3 text-sm text-muted">
        <h2 className="font-display text-base font-black tracking-tight uppercase text-fg">
          What these numbers are
        </h2>
        <p>
          Concurrent players, as Steam counts them: everybody with the game open, wherever they are
          playing it. Valve publishes a live figure per game and an official top 100; we read both
          every ten minutes and keep the samples, so the history here starts when we started
          recording rather than going back to release day.
        </p>
        <p>
          Peaks are Steam&rsquo;s own 24-hour figures rather than the highest sample we happened to
          catch, which is why a peak can be higher than anything on the graph.
        </p>
        <p>
          Hours played has no source at Valve — their charts publish a rank, a count and a peak and
          no playtime at all. It is our own samples added up: each reading stands for the ten
          minutes until the next one, so a day of them is player-hours. Hours observed, in other
          words, which is what every chart site of this kind is counting.
        </p>
      </div>

      <div className="max-w-[68ch] space-y-3 text-sm text-muted">
        <h2 className="font-display text-base font-black tracking-tight uppercase text-fg">
          Players in a game, players on a server
        </h2>
        <p>
          These are not the same measurement and neither is a subset of the other. A game&rsquo;s
          player count includes single-player and matchmaking; a server&rsquo;s is a machine
          somebody runs, which we query directly every few minutes.
        </p>
        <p>
          Read together they say something useful: a game high on this chart with few servers listed
          is one where community hosting has room, and a game with thousands of servers and a modest
          count is one where the players are spread thin.{' '}
          <Link href="/games" className="text-brand hover:underline">
            The catalog
          </Link>{' '}
          is the other half of that picture.
        </p>
      </div>
    </section>
  )
}
