import Link from 'next/link'
import type { Server } from '@/lib/api'
import { LiveProvider } from './live-provider'
import { ServerCard } from './server-card'

export type ServerSectionProps = {
  title: string
  description?: string
  servers: Server[]
  viewAllHref?: string
  /** Link text, because "View all servers" is wrong under "Recently wiped". */
  viewAllLabel?: string
}

/**
 * One titled strip of servers. Every home page collection is this component.
 *
 * Renders nothing at all when the list is empty. That is deliberate and it is
 * what makes the sections independent: a collection whose request failed, or
 * that no data exists for yet — recently wiped, in a catalog with no wipe dates
 * — leaves no empty grid and no apology, it simply is not there. The page above
 * it keeps working.
 *
 * The heading is an h2 and the section is labelled by it, so the page reads as
 * one outline to a screen reader and to a crawler.
 */
export function ServerSection({
  title,
  description,
  servers,
  viewAllHref,
  viewAllLabel = 'View all servers',
}: ServerSectionProps) {
  if (servers.length === 0) return null

  const id = `section-${title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`

  return (
    <section aria-labelledby={id} className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
        <div className="min-w-0">
          <h2 id={id} className="font-display text-xl font-black tracking-tight uppercase">
            {title}
          </h2>
          {description && <p className="mt-1 text-sm text-muted">{description}</p>}
        </div>

        {viewAllHref && (
          <Link
            href={viewAllHref}
            // Three of these point at /search from one page. Warm, that is four
            // segment requests for a listing render nobody has asked for yet —
            // and prefetch dedupes by href, so leaving one of the three on would
            // pay the whole cost anyway. See the note in sidebar.tsx.
            prefetch={false}
            className="shrink-0 text-sm font-medium text-brand transition-colors hover:underline"
          >
            {viewAllLabel}
          </Link>
        )}
      </div>

      {/* One provider per section, over exactly the rows it drew: the poller
          asks for the slugs on screen, and a section that is absent asks for
          nothing. */}
      <LiveProvider slugs={servers.map((server) => server.slug)}>
        <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
          {servers.map((server) => (
            <li key={server.slug}>
              <ServerCard server={server} steam={server.game?.protocol === 'source'} />
            </li>
          ))}
        </ul>
      </LiveProvider>
    </section>
  )
}
