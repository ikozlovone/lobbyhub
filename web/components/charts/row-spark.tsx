/**
 * Two days of a game, at the size of a table cell.
 *
 * The same series the big plot is drawn from, bucketed to two hours so that
 * twenty-four points carry the shape of the day and the night. Inline SVG on
 * the server, like the leader's plot above it: forty of these in a table is
 * forty paths in the HTML and no JavaScript at all, where forty chart
 * components would be a runtime and forty measurements.
 *
 * Deliberately unlabelled. A cell this size cannot carry an axis, and the
 * number it belongs to is in the next column — this is the shape, and the
 * shape is the only thing it claims.
 */
export function RowSpark({
  points,
  label,
  className = 'h-6 w-[7.5rem]',
}: {
  points: number[]
  label: string
  /** Size and visibility belong to the cell it is drawn in, not to the line. */
  className?: string
}) {
  // Two points make a line; one makes a dot that reads as data it is not.
  if (points.length < 3) return null

  const width = 120
  const height = 24
  const peak = Math.max(...points, 1)
  const floor = Math.min(...points)
  // Scaled between this game's own low and high, not from zero: the question a
  // row-sized line answers is "which way and how sharply", and a line pinned
  // to zero flattens every game that never approaches it.
  const range = Math.max(peak - floor, 1)
  const span = points.length - 1

  const coordinates = points.map((players, index) => {
    const x = (index / span) * width
    const y = height - 2 - ((players - floor) / range) * (height - 4)

    return `${x.toFixed(1)},${y.toFixed(1)}`
  })

  const rising = points[points.length - 1] >= points[0]

  return (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      preserveAspectRatio="none"
      className={className}
      role="img"
      aria-label={label}
    >
      <path
        d={`M${coordinates.join('L')}`}
        fill="none"
        stroke={rising ? 'var(--color-brand)' : 'var(--color-offline)'}
        strokeWidth={1.5}
        vectorEffect="non-scaling-stroke"
        strokeLinejoin="round"
        strokeLinecap="round"
      />
    </svg>
  )
}
