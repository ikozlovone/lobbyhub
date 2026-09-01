import { count } from '@/lib/chart'
import type { GameTrend } from '@/lib/api'

/**
 * A game month by month, the way this kind of page has said it since
 * steamcharts made the shape the convention: what a month averaged, what it
 * managed at its peak, and whether it grew.
 *
 * The gain is the column that earns the table. An average says what a month
 * was like and a peak says what it reached, but only the difference between
 * two months answers the question somebody opened the page with.
 *
 * Hours played is ours rather than Valve's — they publish no playtime — and is
 * the same arithmetic every site of this kind does: a reading of N concurrent
 * players stands for the ten minutes until the next reading, so a month of
 * them adds up to player-hours. Which makes it hours *observed*, and a month
 * we only watched half of says half.
 */
export function TrendTable({ trend, name }: { trend: GameTrend; name: string }) {
  if (trend.months.length === 0) return null

  return (
    <section className="rounded-lg border border-line bg-surface" aria-labelledby="trend">
      <header className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-line px-4 py-3">
        <h2 id="trend" className="font-display text-sm font-bold tracking-wide uppercase">
          {name} month by month
        </h2>
        {trend.recording_since && (
          <p className="text-xs text-subtle">
            recorded since {formatMonth(trend.recording_since.slice(0, 7))}
          </p>
        )}
      </header>

      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <caption className="sr-only">
            Average and peak concurrent players for {name} by month, with the change from the
            month before and the hours played.
          </caption>
          <thead>
            <tr className="border-b border-line text-left text-xs text-subtle">
              <th scope="col" className="py-2.5 pr-4 pl-4 font-normal">
                Month
              </th>
              <th scope="col" className="py-2.5 pr-4 text-right font-normal">
                Avg. players
              </th>
              <th scope="col" className="py-2.5 pr-4 text-right font-normal whitespace-nowrap">
                Gain
              </th>
              <th scope="col" className="hidden py-2.5 pr-4 text-right font-normal sm:table-cell">
                % Gain
              </th>
              <th scope="col" className="py-2.5 pr-4 text-right font-normal whitespace-nowrap">
                Peak
              </th>
              <th scope="col" className="hidden py-2.5 pr-4 text-right font-normal md:table-cell">
                Hours played
              </th>
            </tr>
          </thead>
          <tbody>
            {trend.months.map((month) => (
              <tr key={month.month} className="border-b border-line/60 last:border-0">
                <th scope="row" className="py-2.5 pr-4 pl-4 text-left font-medium text-fg">
                  {formatMonth(month.month)}
                  {/* A month we only watched part of is not a month, and the
                      average of eleven days is not comparable to the average
                      of thirty-one. Saying how many days went into it is the
                      cheapest way to keep the row honest. */}
                  {month.days < 28 && (
                    <span className="ml-1.5 text-xs font-normal text-subtle">
                      {month.days}d
                    </span>
                  )}
                </th>
                <td className="tabular py-2.5 pr-4 text-right text-fg">
                  {count(Math.round(month.players_avg))}
                </td>
                <td className="tabular py-2.5 pr-4 text-right">
                  <Change value={month.gain} />
                </td>
                <td className="tabular hidden py-2.5 pr-4 text-right sm:table-cell">
                  <Change value={month.gain_percent} suffix="%" />
                </td>
                <td className="tabular py-2.5 pr-4 text-right text-muted">
                  {count(month.players_peak)}
                </td>
                <td className="tabular hidden py-2.5 pr-4 text-right text-muted md:table-cell">
                  {count(month.hours)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

/**
 * A movement, coloured by direction.
 *
 * Null is the oldest row, which has nothing behind it to be compared with —
 * printed as a dash rather than as a zero, because "no change" and "nothing to
 * compare" are different facts and only one of them is about the game.
 */
function Change({ value, suffix = '' }: { value: number | null; suffix?: string }) {
  if (value === null) return <span className="text-subtle">—</span>

  const rounded = Math.round(value * 10) / 10

  if (rounded === 0) return <span className="text-muted">0{suffix}</span>

  return (
    <span className={rounded > 0 ? 'text-brand' : 'text-danger'}>
      {rounded > 0 ? '+' : '−'}
      {count(Math.abs(rounded))}
      {suffix}
    </span>
  )
}

function formatMonth(month: string) {
  // `YYYY-MM` with a day pinned on, so it is parsed as a date rather than as
  // whatever the runtime makes of a two-part string.
  return new Date(`${month}-01T00:00:00Z`).toLocaleDateString('en-US', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
  })
}
