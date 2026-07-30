'use client'

import Link from 'next/link'
import type { Server } from '@/lib/api'
import { ConnectActions } from './connect-actions'
import { CountryFlag } from './country-flag'
import { useLive } from './live-provider'

/**
 * The listing as cards.
 *
 * The same rows, traded for room: a banner, and the fill bar that tells you at
 * a glance whether a server is busy or merely large. Worth the space when you
 * are choosing where to play rather than comparing numbers.
 */
export function ServerCard({ server, steam }: { server: Server; steam: boolean }) {
  const live = useLive(server.slug, server.live)
  const online = live.status === 'online'
  const fill = live.max_players > 0 ? Math.min(live.players / live.max_players, 1) : 0

  return (
    <article
      // Full height so cards in a row line up: the fill bar and the vote count
      // are a comparison, and they only read as one when they share a baseline.
      className={`group flex h-full flex-col overflow-hidden rounded-xl border bg-surface transition-colors hover:border-line-strong ${
        server.promoted ? 'border-accent/40' : 'border-line'
      }`}
    >
      {server.banner && (
        <div className="aspect-[8/1] overflow-hidden bg-surface-2">
          <img src={server.banner} alt="" aria-hidden loading="lazy" className="size-full object-cover" />
        </div>
      )}

      <div className="flex flex-1 flex-col gap-3 p-3">
        <div className="flex items-start justify-between gap-2">
          <Link
            href={`/servers/${server.slug}`}
            // Same reasoning as the table — see server-table.tsx.
            prefetch={false}
            className="line-clamp-2 font-medium transition-colors group-hover:text-brand"
          >
            {server.name}
          </Link>
          {server.promoted && (
            <span className="shrink-0 rounded-sm bg-accent/15 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-accent uppercase">
              Promoted
            </span>
          )}
        </div>

        <span className="flex items-center gap-2">
          {server.country && <CountryFlag country={server.country} city={server.city} />}
          <ConnectActions address={server.address} steam={steam} className="min-w-0" />
        </span>

        <div className="mt-auto space-y-2">
          <div className="flex items-baseline justify-between gap-2 text-sm">
            <span className="flex items-center gap-1.5 text-xs">
              <span aria-hidden className={`size-1.5 rounded-full ${online ? 'bg-online' : 'bg-offline'}`} />
              <span className={online ? 'text-online' : 'text-subtle'}>
                {online ? 'Online' : 'Offline'}
              </span>
            </span>
            <span className="tabular">
              <span className={online ? '' : 'text-subtle'}>
                {online ? live.players.toLocaleString('en-US') : '—'}
              </span>
              <span className="text-subtle">/{live.max_players.toLocaleString('en-US')}</span>
            </span>
          </div>

          <span aria-hidden className="block h-0.5 w-full overflow-hidden rounded-full bg-line">
            <span
              className="block h-full bg-brand transition-[width] duration-300"
              style={{ width: `${Math.round(fill * 100)}%` }}
            />
          </span>

          <div className="flex items-center justify-between gap-2 text-xs text-subtle">
            <span className="truncate">{server.map ?? server.version ?? '—'}</span>
            <span className="tabular shrink-0">
              {server.votes.toLocaleString('en-US')} votes
            </span>
          </div>
        </div>
      </div>
    </article>
  )
}
