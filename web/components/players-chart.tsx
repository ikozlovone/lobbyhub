'use client'

import { useMemo, useState } from 'react'
import type { History, HistoryPoint } from '@/lib/api'

/**
 * Players over time — one series, so no legend box: the heading names it.
 *
 * Plain SVG on purpose. The shape is an area with a 2px line; grid and axes stay
 * recessive so the data is the only thing with weight. Hover gives a crosshair
 * and a tooltip, because an SVG chart on a web page is an interactive chart.
 */

const RANGES = [
  { key: '24h', label: '24h' },
  { key: '7d', label: '7 days' },
  { key: '30d', label: '30 days' },
  { key: '1y', label: '1 year' },
]

const WIDTH = 720
const HEIGHT = 220
const PAD = { top: 12, right: 8, bottom: 22, left: 40 }

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
  const [hover, setHover] = useState<number | null>(null)

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

  const points = history?.points ?? []
  const geometry = useMemo(() => buildGeometry(points), [points])

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

      <div className={`px-2 py-3 transition-opacity ${loading ? 'opacity-50' : ''}`}>
        {geometry ? (
          <>
            <svg
              viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
              className="h-56 w-full touch-none"
              role="img"
              aria-label={`Players online over the last ${range}. Peak ${geometry.max}, latest ${geometry.last}.`}
              onPointerMove={(event) => {
                const box = event.currentTarget.getBoundingClientRect()
                const x = ((event.clientX - box.left) / box.width) * WIDTH
                setHover(geometry.indexAt(x))
              }}
              onPointerLeave={() => setHover(null)}
            >
              {geometry.ticks.map((tick) => (
                <g key={tick.value}>
                  <line
                    x1={PAD.left}
                    x2={WIDTH - PAD.right}
                    y1={tick.y}
                    y2={tick.y}
                    stroke="var(--color-line)"
                    strokeWidth={1}
                  />
                  <text
                    x={PAD.left - 8}
                    y={tick.y + 4}
                    textAnchor="end"
                    className="tabular"
                    fontSize={10}
                    fill="var(--color-subtle)"
                  >
                    {tick.label}
                  </text>
                </g>
              ))}

              <defs>
                <linearGradient id="players-fill" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor="var(--color-brand)" stopOpacity="0.35" />
                  <stop offset="100%" stopColor="var(--color-brand)" stopOpacity="0.02" />
                </linearGradient>
              </defs>

              <path d={geometry.area} fill="url(#players-fill)" />
              <path
                d={geometry.line}
                fill="none"
                stroke="var(--color-brand)"
                strokeWidth={2}
                strokeLinejoin="round"
                strokeLinecap="round"
              />

              {/* Downtime is marked on the baseline, not by recolouring the series. */}
              {geometry.offline.map((segment) => (
                <rect
                  key={segment.x}
                  x={segment.x}
                  y={HEIGHT - PAD.bottom - 3}
                  width={segment.width}
                  height={3}
                  fill="var(--color-offline)"
                />
              ))}

              {hover !== null && geometry.at(hover) && (
                <g>
                  <line
                    x1={geometry.at(hover)!.x}
                    x2={geometry.at(hover)!.x}
                    y1={PAD.top}
                    y2={HEIGHT - PAD.bottom}
                    stroke="var(--color-line-strong)"
                    strokeWidth={1}
                  />
                  <circle
                    cx={geometry.at(hover)!.x}
                    cy={geometry.at(hover)!.y}
                    r={4}
                    fill="var(--color-brand)"
                    stroke="var(--color-surface)"
                    strokeWidth={2}
                  />
                </g>
              )}

              <text x={PAD.left} y={HEIGHT - 6} fontSize={10} fill="var(--color-subtle)">
                {geometry.firstLabel}
              </text>
              <text
                x={WIDTH - PAD.right}
                y={HEIGHT - 6}
                textAnchor="end"
                fontSize={10}
                fill="var(--color-subtle)"
              >
                {geometry.lastLabel}
              </text>
            </svg>

            <p className="px-2 text-xs text-subtle" aria-live="polite">
              {hover !== null && points[hover] ? (
                <>
                  <span className="tabular text-fg">
                    {points[hover].players.toLocaleString('en-US')}
                  </span>{' '}
                  players · {formatStamp(points[hover].at, history!.source)}
                  {points[hover].online === false && ' · offline'}
                  {points[hover].uptime !== undefined && ` · ${points[hover].uptime}% uptime`}
                </>
              ) : (
                <>
                  Peak <span className="tabular text-fg">{geometry.max.toLocaleString('en-US')}</span>{' '}
                  · {points.length} data points from{' '}
                  {history?.source === 'raw' ? 'individual checks' : 'daily summaries'}
                </>
              )}
            </p>
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

function buildGeometry(points: HistoryPoint[]) {
  if (points.length === 0) return null

  const max = Math.max(...points.map((point) => point.players), 1)
  const niceMax = niceCeiling(max)
  const innerWidth = WIDTH - PAD.left - PAD.right
  const innerHeight = HEIGHT - PAD.top - PAD.bottom
  const step = points.length > 1 ? innerWidth / (points.length - 1) : 0

  const x = (index: number) => PAD.left + index * step
  const y = (value: number) => PAD.top + innerHeight - (value / niceMax) * innerHeight

  const line = points.map((point, index) => `${index ? 'L' : 'M'}${x(index)},${y(point.players)}`).join(' ')
  const area = `${line} L${x(points.length - 1)},${HEIGHT - PAD.bottom} L${x(0)},${HEIGHT - PAD.bottom} Z`

  const offline = points
    .map((point, index) => ({ point, index }))
    .filter(({ point }) => point.online === false)
    .map(({ index }) => ({ x: x(index) - step / 2, width: Math.max(step, 2) }))

  const ticks = [0, 0.5, 1].map((fraction) => ({
    value: Math.round(niceMax * fraction),
    label: compact(Math.round(niceMax * fraction)),
    y: y(niceMax * fraction),
  }))

  return {
    line,
    area,
    offline,
    ticks,
    max,
    last: points[points.length - 1].players,
    firstLabel: formatStamp(points[0].at),
    lastLabel: formatStamp(points[points.length - 1].at),
    at: (index: number) =>
      points[index] ? { x: x(index), y: y(points[index].players) } : null,
    indexAt: (position: number) =>
      Math.max(0, Math.min(points.length - 1, Math.round((position - PAD.left) / (step || 1)))),
  }
}

function niceCeiling(value: number) {
  const magnitude = 10 ** Math.floor(Math.log10(value))
  return Math.ceil(value / magnitude) * magnitude
}

function compact(value: number) {
  return value >= 1000 ? `${(value / 1000).toFixed(value >= 10_000 ? 0 : 1)}k` : String(value)
}

function formatStamp(stamp: string, source?: 'raw' | 'daily') {
  const date = new Date(stamp)
  const daily = source === 'daily' || !stamp.includes('T')

  return daily
    ? date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
    : date.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}
