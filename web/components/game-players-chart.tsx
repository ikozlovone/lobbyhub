'use client'

import { useMemo, useState } from 'react'
import {
  Area,
  CartesianGrid,
  ComposedChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import type { GamePlayers } from '@/lib/api'
import { compact, count, formatStamp, xTicks, yTicks } from '@/lib/chart'

/**
 * How many people are playing a game, over time.
 *
 * Drawn like the server chart — same axis treatment, same wash under the line,
 * same four evenly spaced stamps — because a visitor reads them as one thing.
 * What it does not carry is the server chart's downtime bands: a game is never
 * offline, and a gap here means the collector did not run, which is our
 * problem rather than a fact about the game.
 *
 * The empty state is the honest one. These tables begin the day the collector
 * was switched on, so a range with nothing in it is not a game nobody plays —
 * it is a question we have not been recording long enough to answer, and it
 * says so.
 */

const RANGES = [
  { key: '24h', label: '24h' },
  { key: '7d', label: '7 days' },
  { key: '30d', label: '30 days' },
  { key: '1y', label: '1 year' },
]

type Row = { at: string; players: number; peak?: number; t: number }

export function GamePlayersChart({
  slug,
  name,
  initial,
  apiUrl,
  framed = true,
}: {
  slug: string
  name: string
  initial: GamePlayers | null
  apiUrl: string
  /**
   * Whether to draw its own panel. False when it sits inside one already —
   * a card in a card is a border a reader has to explain to themselves.
   */
  framed?: boolean
}) {
  const [history, setHistory] = useState<GamePlayers | null>(initial)
  const [range, setRange] = useState(initial?.range ?? '24h')
  const [loading, setLoading] = useState(false)

  async function selectRange(next: string) {
    if (next === range) return

    setRange(next)
    setLoading(true)

    try {
      const response = await fetch(`${apiUrl}/games/${slug}/players?range=${next}`)

      if (response.ok) setHistory((await response.json()).data)
    } finally {
      setLoading(false)
    }
  }

  const source = history?.source
  const rows = useMemo<Row[]>(
    () => (history?.points ?? []).map((point) => ({ ...point, t: Date.parse(point.at) })),
    [history],
  )

  const peak = useMemo(() => Math.max(...rows.map((row) => row.peak ?? row.players), 0), [rows])
  const ticks = useMemo(() => yTicks(peak), [peak])
  const stamps = useMemo(() => xTicks(rows.map((row) => row.t), source), [rows, source])
  const since = history?.recording_since

  return (
    <section className={framed ? 'rounded-lg border border-line bg-surface' : ''}>
      <header className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
        <h2 className="font-display text-sm font-bold tracking-wide uppercase">
          Players in game
        </h2>
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

      <div className={`px-2 py-3 transition-opacity ${loading ? 'opacity-50' : ''}`}>
        {rows.length > 1 ? (
          <>
            <div
              className="relative h-64 w-full"
              role="img"
              aria-label={`${name} players over the last ${range}. Peak ${count(peak)}, latest ${count(
                rows[rows.length - 1].players,
              )}.`}
            >
              <ResponsiveContainer width="100%" height="100%" className="absolute inset-0">
                <ComposedChart
                  data={rows}
                  margin={{ top: 8, right: 12, bottom: 0, left: 0 }}
                  accessibilityLayer
                >
                  <defs>
                    <linearGradient id="game-players-fill" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor="var(--color-brand)" stopOpacity={0.28} />
                      <stop offset="100%" stopColor="var(--color-brand)" stopOpacity={0.01} />
                    </linearGradient>
                  </defs>

                  <CartesianGrid vertical={false} stroke="var(--color-line)" strokeWidth={1} />

                  <XAxis
                    dataKey="t"
                    type="number"
                    scale="time"
                    domain={['dataMin', 'dataMax']}
                    ticks={stamps}
                    tickFormatter={(value: number) => formatStamp(value, source)}
                    tickLine={false}
                    axisLine={false}
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
                    width={48}
                    tick={{ fill: 'var(--color-subtle)', fontSize: 11 }}
                  />

                  <Tooltip
                    cursor={{ stroke: 'var(--color-line-strong)', strokeWidth: 1 }}
                    content={<Readout source={source} />}
                    isAnimationActive={false}
                  />

                  {/* On a daily range the average is the line and the peak is
                      the ceiling above it — two series that answer different
                      questions about the same day. */}
                  {source === 'daily' && (
                    <Area
                      type="monotone"
                      dataKey="peak"
                      stroke="var(--color-accent)"
                      strokeWidth={1}
                      strokeDasharray="3 3"
                      fill="none"
                      dot={false}
                      isAnimationActive={false}
                    />
                  )}

                  <Area
                    type="monotone"
                    dataKey="players"
                    stroke="var(--color-brand)"
                    strokeWidth={2}
                    strokeLinejoin="round"
                    strokeLinecap="round"
                    fill="url(#game-players-fill)"
                    dot={false}
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
              Peak <span className="tabular text-fg">{count(peak)}</span> · {rows.length} points
              from {source === 'raw' ? 'ten-minute samples' : 'daily summaries'}
              {since && <> · recording since {formatStamp(since, 'daily')}</>}
            </p>
          </>
        ) : (
          <Empty since={since} name={name} />
        )}
      </div>
    </section>
  )
}

/**
 * Nothing to draw yet, said without pretending otherwise.
 *
 * There are two ways to be empty and they are different facts: this range
 * started before we did, or nothing has been recorded at all. Both are ours to
 * own rather than the game's.
 */
function Empty({ since, name }: { since?: string | null; name: string }) {
  return (
    <div className="px-4 py-12 text-center">
      <p className="text-sm text-muted">
        {since
          ? `Not enough history in this range yet — ${name} has been recorded since ${formatStamp(
              since,
              'daily',
            )}.`
          : `${name} has no recorded history yet.`}
      </p>
      <p className="mt-1 text-xs text-subtle">
        Player counts are sampled every ten minutes; a longer range fills in as they accumulate.
      </p>
    </div>
  )
}

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
        <span className="tabular text-sm font-semibold text-fg">{count(row.players)}</span>
        <span className="text-xs text-muted">
          {source === 'daily' ? 'average' : 'players'}
        </span>
      </p>
      {row.peak !== undefined && (
        <p className="mt-0.5 flex items-baseline gap-1.5 text-xs">
          <span aria-hidden className="h-px w-3 rounded-full bg-accent" />
          <span className="tabular text-muted">{count(row.peak)}</span>
          <span className="text-subtle">peak</span>
        </p>
      )}
      <p className="mt-0.5 text-xs text-subtle">{formatStamp(row.at, source)}</p>
    </div>
  )
}
