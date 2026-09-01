import { compact, count, formatStamp } from '@/lib/chart'

/**
 * A day of a game's player count, drawn on the server.
 *
 * Not a decorative sparkline: it is the measurement itself, and it is the one
 * plot on this page. Drawn as inline SVG rather than with the chart library
 * because the ranking below it is the reason anybody is here — the page should
 * paint before any JavaScript runs, and a hundred rows of table plus a
 * client-side chart runtime is the wrong trade for one series.
 *
 * The path is built from the same downsampled points the interactive chart on
 * the game's own page uses, so the shape a visitor sees here is the shape they
 * find when they click through.
 */
export function PlayersSpark({
  points,
  label,
  height = 96,
}: {
  points: { at: string; players: number }[]
  label: string
  height?: number
}) {
  if (points.length < 2) return null

  // A fixed viewBox with preserveAspectRatio off: the SVG stretches to the
  // panel and the stroke is drawn in its own units, so one path serves every
  // width without a measurement or a resize listener.
  const width = 1000
  const peak = Math.max(...points.map((point) => point.players), 1)
  const span = Math.max(points.length - 1, 1)

  const coordinates = points.map((point, index) => {
    const x = (index / span) * width
    // A tenth of headroom, so the peak never sits on the top edge.
    const y = height - (point.players / (peak * 1.1)) * height

    return `${x.toFixed(1)},${y.toFixed(1)}`
  })

  const line = `M${coordinates.join('L')}`
  const area = `${line}L${width},${height}L0,${height}Z`

  const first = points[0]
  const last = points[points.length - 1]

  return (
    <figure className="m-0">
      <svg
        viewBox={`0 0 ${width} ${height}`}
        preserveAspectRatio="none"
        className="h-24 w-full sm:h-28"
        role="img"
        aria-label={`${label}: ${count(first.players)} players at ${formatStamp(
          first.at,
        )}, ${count(last.players)} at ${formatStamp(last.at)}, peak ${count(peak)}.`}
      >
        <defs>
          <linearGradient id="spark-fill" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor="var(--color-brand)" stopOpacity={0.3} />
            <stop offset="100%" stopColor="var(--color-brand)" stopOpacity={0.02} />
          </linearGradient>
        </defs>

        <path d={area} fill="url(#spark-fill)" />
        <path
          d={line}
          fill="none"
          stroke="var(--color-brand)"
          strokeWidth={2}
          // The path is drawn in stretched user units, so the stroke would be
          // stretched with it: this keeps it an even two pixels at any width.
          vectorEffect="non-scaling-stroke"
          strokeLinejoin="round"
          strokeLinecap="round"
        />
      </svg>

      <figcaption className="mt-1.5 flex items-center justify-between text-xs text-subtle">
        <span>{formatStamp(first.at)}</span>
        <span>
          peak <span className="tabular text-muted">{compact(peak)}</span>
        </span>
        <span>{formatStamp(last.at)}</span>
      </figcaption>
    </figure>
  )
}
