export default function Loading() {
  return (
    <div className="animate-pulse space-y-6" aria-busy="true" aria-label="Loading servers">
      {/* The same shapes in the same places as the real page — hero, filter
          panel, table. A skeleton that does not match is just a second flash. */}
      <div className="h-44 rounded-2xl bg-surface" />
      <div className="grid gap-6 2xl:grid-cols-[minmax(0,1fr)_18rem]">
        <div className="space-y-4">
          <div className="h-44 rounded-2xl bg-surface" />
          <div className="h-10 w-72 rounded-xl bg-surface" />
          <div className="h-96 rounded-2xl bg-surface" />
        </div>
        <div className="hidden h-80 rounded-2xl bg-surface 2xl:block" />
      </div>
    </div>
  )
}
