import type { Standing } from '@/lib/api'
import { Icon } from './icons'

/**
 * Position, points and the gap to the leader — the first thing on the page,
 * because it is the one number an owner opens their card to look at.
 */
export function RankBar({ standing }: { standing: Standing }) {
  const share = standing.leader_points > 0 ? standing.points / standing.leader_points : 0

  return (
    <section className="rounded-lg border border-line bg-surface p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 className="font-display flex items-center gap-2 text-lg font-bold">
          <span className="text-accent">
            <Icon.star />
          </span>
          #{standing.position} <span className="text-subtle">in the top</span>
        </h2>
        <p className="tabular text-sm text-muted">
          Points: {standing.points.toLocaleString('en-US')}
          <span className="text-subtle">/{standing.leader_points.toLocaleString('en-US')}</span>
        </p>
      </div>

      <span aria-hidden className="mt-2 block h-2 overflow-hidden rounded-full bg-line">
        <span
          className="block h-full rounded-full bg-brand transition-[width] duration-500"
          style={{ width: `${Math.max(Math.round(share * 100), 2)}%` }}
        />
      </span>
    </section>
  )
}
