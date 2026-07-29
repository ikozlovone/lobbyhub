import Link from 'next/link'
import type { RecentVote } from '@/lib/api'
import { RelativeTime } from './relative-time'

/**
 * Who has been voting, lately.
 *
 * The one place a listing shows that other people are here — the competitor
 * fills the same rail with reviews, which is a moderation queue we do not have.
 * Votes are already public in aggregate; this only says when, and under the
 * nickname the voter chose to publish so an owner could reward them.
 */
export function RecentVotes({ votes }: { votes: RecentVote[] }) {
  if (votes.length === 0) return null

  return (
    <section className="rounded-2xl border border-line bg-surface">
      <h2 className="font-display border-b border-line px-4 py-3 text-sm font-bold tracking-wide uppercase">
        Recent votes
      </h2>

      <ul className="divide-y divide-line">
        {votes.map((vote, index) => (
          <li key={`${vote.server.slug}-${vote.at}-${index}`} className="px-4 py-3">
            <p className="flex items-baseline justify-between gap-2 text-xs">
              <span className="truncate text-muted">{vote.nickname ?? 'Someone'}</span>
              {vote.at && <RelativeTime at={vote.at} className="shrink-0 text-subtle" />}
            </p>
            <Link
              href={`/servers/${vote.server.slug}`}
              className="mt-0.5 block truncate text-sm transition-colors hover:text-brand"
            >
              {vote.server.name}
            </Link>
          </li>
        ))}
      </ul>
    </section>
  )
}
