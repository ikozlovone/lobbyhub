export default function Loading() {
  return (
    <div className="animate-pulse space-y-6" aria-busy="true" aria-label="Loading servers">
      <div className="h-8 w-72 rounded bg-surface-2" />
      <div className="grid gap-6 lg:grid-cols-[15rem_1fr]">
        <div className="h-80 rounded bg-surface" />
        <div className="h-96 rounded-lg border border-line bg-surface" />
      </div>
    </div>
  )
}
