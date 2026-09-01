export default function Loading() {
  return (
    <div className="animate-pulse space-y-6" aria-busy="true" aria-label="Loading player count">
      {/* The same shapes in the same places as the real page — heading, the
          four readings, the plot. A skeleton that does not match is just a
          second flash. */}
      <div className="space-y-3">
        <div className="h-3 w-40 rounded bg-surface" />
        <div className="h-9 w-80 max-w-full rounded bg-surface" />
        <div className="h-4 w-full max-w-[42rem] rounded bg-surface" />
      </div>
      <div className="h-[26rem] rounded-lg bg-surface" />
      <div className="grid gap-6 md:grid-cols-2">
        <div className="h-32 rounded-lg bg-surface" />
        <div className="h-32 rounded-lg bg-surface" />
      </div>
    </div>
  )
}
