'use client'

import { useMemo, useState } from 'react'
import {
  Area,
  CartesianGrid,
  ComposedChart,
  ReferenceArea,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import type { History, HistoryPoint } from '@/lib/api'

/**
 * Players over time — one series, so no legend box: the heading names it.
 *
 * Drawn with Recharts, so the axis ticks, the responsive box, the crosshair and
 * the keyboard layer are library behaviour rather than arithmetic maintained
 * here. What is kept locally is the part that is ours: the palette, the shape of
 * the tooltip, and the downtime bands, which are a fact about server monitoring
 * that no chart library ships.
 */

const RANGES = [
  { key: '24h', label: '24h' },
  { key: '7d', label: '7 days' },
  { key: '30d', label: '30 days' },
  { key: '1y', label: '1 year' },
]

/** A point with its time as a number, because the x axis is a real time scale. */
type Row = HistoryPoint & { t: number }

export function PlayersChart({
  slug,
  initial,
  apiUrl,
}: {
  slug: string
  initial: History | null
  apiUrl: string
}) {
  const [history, setHistory] = useState<History | null>(initial)
  const [range, setRange] = useState(initial?.range ?? '24h')
  const [loading, setLoading] = useState(false)

  async function selectRange(next: string) {
    if (next === range) return
    setRange(next)
    setLoading(true)
    try {
      const response = await fetch(`${apiUrl}/servers/${slug}/history?range=${next}`)
      if (response.ok) setHistory((await response.json()).data)
    } finally {
      setLoading(false)
    }
  }

  const source = history?.source
  const points = useMemo(() => history?.points ?? [], [history])
  const rows = useMemo<Row[]>(
    () => points.map((point) => ({ ...point, t: Date.parse(point.at) })),
    [points],
  )
  const peak = useMemo(() => Math.max(...points.map((point) => point.players), 0), [points])
  const ticks = useMemo(() => yTicks(peak), [peak])
  const stamps = useMemo(() => xTicks(rows, source), [rows, source])
  const downtime = useMemo(() => downtimeBands(rows), [rows])

  return (
    <section className="rounded-lg border border-line bg-surface">
      <header className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
        <h2 className="font-display text-sm font-bold tracking-wide uppercase">Players online</h2>
        <div className="flex gap-1" role="group" aria-label="Time range">
          {RANGES.map((option) => (
            <button
              key={option.key}
              type="button"
              onClick={() => selectRange(option.key)}
              aria-pressed={range === option.key}
              className={`cursor-pointer rounded px-2 py-1 text-xs transition-colors ${
                range === option.key
                  ? 'bg-surface-2 text-fg'
                  : 'text-subtle hover:bg-surface-2 hover:text-fg'
              }`}
            >
              {option.label}
            </button>
          ))}
        </div>
      </header>

      {/* A reload holds the previous render at half strength: no skeleton, no jump. */}
      <div className={`px-2 py-3 transition-opacity ${loading ? 'opacity-50' : ''}`}>
        {rows.length > 0 ? (
          <>
            {/* The plot is taken out of the flow: a rendered chart carries the
                width it was last measured at, and in the page grid that width
                becomes a floor the column can never shrink below. Positioned
                absolutely it has no say in the layout — it only follows it. */}
            <div
              className="relative h-56 w-full"
              role="img"
              aria-label={`Players online over the last ${range}. Peak ${peak}, latest ${
                rows[rows.length - 1].players
              }.`}
            >
              <ResponsiveContainer width="100%" height="100%" className="absolute inset-0">
                <ComposedChart
                  data={rows}
                  margin={{ top: 8, right: 12, bottom: 0, left: 0 }}
                  // Arrow keys walk the series and announce each point, so the
                  // numbers are not locked behind a pointer.
                  accessibilityLayer
                >
                  <defs>
                    {/* A wash, not a block — the line is what carries the shape. */}
                    <linearGradient id="players-fill" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor="var(--color-brand)" stopOpacity={0.28} />
                      <stop offset="100%" stopColor="var(--color-brand)" stopOpacity={0.01} />
                    </linearGradient>
                  </defs>

                  <CartesianGrid
                    vertical={false}
                    stroke="var(--color-line)"
                    strokeWidth={1}
                  />

                  <XAxis
                    dataKey="t"
                    // A real time scale, not one slot per check: monitoring
                    // samples arrive at uneven intervals, and spacing them
                    // evenly would draw a gap as if it were a quiet minute.
                    type="number"
                    scale="time"
                    domain={['dataMin', 'dataMax']}
                    ticks={stamps}
                    tickFormatter={(value: number) => formatStamp(value, source)}
                    tickLine={false}
                    axisLine={false}
                    // Narrow screens drop labels rather than overlapping them.
                    minTickGap={40}
                    tickMargin={10}
                    tick={{ fill: 'var(--color-subtle)', fontSize: 11 }}
                  />

                  <YAxis
                    domain={[0, ticks[ticks.length - 1]]}
                    ticks={ticks}
                    tickFormatter={compact}
                    tickLine={false}
                    axisLine={false}
                    width={44}
                    tick={{ fill: 'var(--color-subtle)', fontSize: 11 }}
                  />

                  {/* Downtime is a band behind the series, not a recolouring of
                      it: the player count during an outage is still zero-ish
                      data, and dyeing the line would say otherwise. */}
                  {downtime.map((band) => (
                    <ReferenceArea
                      key={band.from}
                      x1={band.from}
                      x2={band.to}
                      fill="var(--color-offline)"
                      fillOpacity={0.14}
                      stroke="none"
                    />
                  ))}

                  <Tooltip
                    cursor={{ stroke: 'var(--color-line-strong)', strokeWidth: 1 }}
                    content={<Readout source={source} />}
                    isAnimationActive={false}
                  />

                  <Area
                    type="monotone"
                    dataKey="players"
                    stroke="var(--color-brand)"
                    strokeWidth={2}
                    strokeLinejoin="round"
                    strokeLinecap="round"
                    fill="url(#players-fill)"
                    dot={false}
                    // The surface ring keeps the marker legible wherever it
                    // lands on the fill.
                    activeDot={{
                      r: 4,
                      fill: 'var(--color-brand)',
                      stroke: 'var(--color-surface)',
                      strokeWidth: 2,
                    }}
                    isAnimationActive={false}
                  />
                </ComposedChart>
              </ResponsiveContainer>
            </div>

            <p className="px-2 pt-1 text-xs text-subtle">
              Peak <span className="tabular text-fg">{peak.toLocaleString('en-US')}</span> ·{' '}
              {rows.length} data points from{' '}
              {source === 'raw' ? 'individual checks' : 'daily summaries'}
            </p>

            {/* Hovering is not the only way to a number. */}
            <details className="px-2 pt-2 text-xs text-subtle">
              <summary className="cursor-pointer transition-colors hover:text-fg">
                Show the numbers
              </summary>
              <div className="mt-2 max-h-64 overflow-y-auto">
                <table className="w-full text-left">
                  <thead className="sticky top-0 bg-surface text-subtle">
                    <tr>
                      <th className="py-1 font-normal">Time</th>
                      <th className="py-1 text-right font-normal">Players</th>
                      <th className="py-1 text-right font-normal">State</th>
                    </tr>
                  </thead>
                  <tbody className="text-muted">
                    {rows.map((row) => (
                      <tr key={row.at} className="border-t border-line">
                        <td className="py-1">{formatStamp(row.at, source)}</td>
                        <td className="tabular py-1 text-right text-fg">
                          {row.players.toLocaleString('en-US')}
                        </td>
                        <td className="py-1 text-right">
                          {row.online === false ? 'offline' : 'online'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </details>
          </>
        ) : (
          <p className="px-2 py-12 text-center text-sm text-subtle">
            No history for this range yet — it fills in as we keep checking the server.
          </p>
        )}
      </div>
    </section>
  )
}

/**
 * The hovered point.
 *
 * The number leads and the label follows: the reader already knows they are on
 * the players line, and what they came for is the count.
 */
function Readout({
  active,
  payload,
  source,
}: {
  active?: boolean
  payload?: { payload: Row }[]
  source?: 'raw' | 'daily'
}) {
  const row = active ? payload?.[0]?.payload : undefined

  if (!row) return null

  return (
    <div className="rounded-lg border border-line bg-surface-2 px-3 py-2 shadow-lg">
      <p className="flex items-baseline gap-1.5">
        <span aria-hidden className="h-0.5 w-3 rounded-full bg-brand" />
        <span className="tabular text-sm font-semibold text-fg">
          {row.players.toLocaleString('en-US')}
        </span>
        <span className="text-xs text-muted">players</span>
      </p>
      <p className="mt-0.5 text-xs text-subtle">
        {formatStamp(row.at, source)}
        {row.online === false && ' · offline'}
        {row.uptime !== undefined && ` · ${row.uptime}% uptime`}
      </p>
    </div>
  )
}

/** Runs of consecutive offline checks, as bands the chart can paint. */
function downtimeBands(rows: Row[]) {
  const bands: { from: number; to: number }[] = []

  for (let index = 0; index < rows.length; index++) {
    if (rows[index].online !== false) continue

    let end = index

    while (end + 1 < rows.length && rows[end + 1].online === false) end++

    // A run has no width of its own until it reaches the next check, so the
    // band is drawn to it — which is also the truth: the server was down at
    // least until we looked again. At the very end of the series there is no
    // next check, so it borrows the previous one instead.
    const last = rows.length - 1

    bands.push(
      end < last
        ? { from: rows[index].t, to: rows[end + 1].t }
        : { from: rows[Math.max(0, index - 1)].t, to: rows[last].t },
    )

    index = end
  }

  return bands
}

/**
 * Four stamps spread evenly across the span.
 *
 * Left to itself the library labels whichever moments its own tick maths lands
 * on, which on irregularly sampled data comes out lopsided. Even spacing is what
 * a time axis is read as, and on a linear scale it is also true.
 *
 * They sit an eighth of the span in from each end, keeping the step uniform
 * while leaving the first and last labels room to be centred on their tick
 * instead of hanging off the edge of the plot.
 *
 * Stamps that read the same are dropped: a two-day span of daily summaries
 * would otherwise put "Jul 27" under three of the four ticks.
 */
function xTicks(rows: Row[], source?: 'raw' | 'daily') {
  if (rows.length < 2) return undefined

  const from = rows[0].t
  const span = rows[rows.length - 1].t - from
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
function yTicks(peak: number) {
  const step = niceStep(Math.max(peak, 1) / 3)

  return [0, step, step * 2, step * 3]
}

/** The next 1 / 2 / 2.5 / 5 / 10 of a decade at or above the value. */
function niceStep(value: number) {
  const magnitude = 10 ** Math.floor(Math.log10(value))
  const fraction = [1, 2, 2.5, 5, 10].find((candidate) => value <= candidate * magnitude) ?? 10

  return Math.max(1, fraction * magnitude)
}

function compact(value: number) {
  return value >= 1000 ? `${(value / 1000).toFixed(value >= 10_000 ? 0 : 1)}k` : String(value)
}

function formatStamp(stamp: string | number, source?: 'raw' | 'daily') {
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
