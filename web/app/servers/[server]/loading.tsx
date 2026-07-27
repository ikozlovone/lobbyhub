/**
 * Doubles as the Suspense boundary for the route: with Cache Components,
 * reading params without one blocks the shell instead of streaming it.
 */
export default function Loading() {
  return (
    <div className="animate-pulse space-y-6" aria-busy="true" aria-label="Loading server">
      <div className="h-3 w-48 rounded bg-surface-2" />
      <div className="h-8 w-80 rounded bg-surface-2" />
      <div className="h-24 rounded-lg border border-line bg-surface" />
      <div className="h-64 rounded-lg border border-line bg-surface" />
    </div>
  )
}
