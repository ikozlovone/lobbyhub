/**
 * The arithmetic behind both charts on this site.
 *
 * Shared rather than duplicated because the two are read as one thing: a
 * visitor who looks at a server's players and then at its game's should not
 * find one axis labelled `1.2k` and the other `1,200`, or four evenly spaced
 * stamps on one and whatever the library picked on the other. The series differ
 * (one is a server, one is a whole game on Steam); the way they are drawn does
 * not.
 *
 * Extracted from PlayersChart, where all of this was written first.
 */

/** Where a series comes from: individual checks, or a daily rollup. */
export type Source = 'raw' | 'daily'

/**
 * Four stamps spread evenly across the span.
 *
 * Left to itself the library labels whichever moments its own tick maths lands
 * on, which on irregularly sampled data comes out lopsided. Even spacing is
 * what a time axis is read as, and on a linear scale it is also true.
 *
 * They sit an eighth of the span in from each end, keeping the step uniform
 * while leaving the first and last labels room to be centred on their tick
 * instead of hanging off the edge of the plot.
 *
 * Stamps that read the same are dropped: a two-day span of daily summaries
 * would otherwise put "Jul 27" under three of the four ticks.
 */
export function xTicks(times: number[], source?: Source) {
  if (times.length < 2) return undefined

  const from = times[0]
  const span = times[times.length - 1] - from
  const seen = new Set<string>()

  return [1, 3, 5, 7]
    .map((eighth) => Math.round(from + (span * eighth) / 8))
    .filter((stamp) => {
      const label = formatStamp(stamp, source)

      if (seen.has(label)) return false

      seen.add(label)

      return true
    })
}

/**
 * Round ticks from zero to just above the peak — the values the tooltip does
 * not give. Three even steps, so a peak of 1,203 tops out at 1,500 rather than
 * at 2,000 with half the panel left empty.
 */
export function yTicks(peak: number) {
  const step = niceStep(Math.max(peak, 1) / 3)

  return [0, step, step * 2, step * 3]
}

/** The next 1 / 2 / 2.5 / 5 / 10 of a decade at or above the value. */
function niceStep(value: number) {
  const magnitude = 10 ** Math.floor(Math.log10(value))
  const fraction = [1, 2, 2.5, 5, 10].find((candidate) => value <= candidate * magnitude) ?? 10

  return Math.max(1, fraction * magnitude)
}

/**
 * Axis labels, where the digits matter less than the shape.
 *
 * Millions get a decimal because a chart of Counter-Strike tops out somewhere
 * between 1M and 2M and `1M` for both ends of that would be a flat axis.
 */
export function compact(value: number) {
  if (value >= 1_000_000) return `${(value / 1_000_000).toFixed(value >= 10_000_000 ? 0 : 1)}M`
  if (value >= 1000) return `${(value / 1000).toFixed(value >= 10_000 ? 0 : 1)}k`

  return String(value)
}

export function formatStamp(stamp: string | number, source?: Source) {
  const date = new Date(stamp)
  const daily = source === 'daily' || (typeof stamp === 'string' && !stamp.includes('T'))

  return daily
    ? date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
    : date.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      })
}

/** Full precision, for the places a number is the point rather than the shape. */
export function count(value: number) {
  return value.toLocaleString('en-US')
}
