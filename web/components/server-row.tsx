'use client'

import Link from 'next/link'
import type { Server } from '@/lib/api'
import { useLive } from './live-provider'

/**
 * One row of a listing. Client-side only because of the live counters —
 * everything else it renders comes from the cached page shell.
 */
export function ServerRow({ server, rank }: { server: Server; rank: number }) {
  const live = useLive(server.slug, server.live)
  const online = live.status === 'online'
  const fill = live.max_players > 0 ? Math.min(live.players / live.max_players, 1) : 0

  return (
    <Link
      href={`/servers/${server.slug}`}
      className="group grid cursor-pointer grid-cols-[2rem_1fr_auto] items-center gap-3 border-b border-line px-3 py-3 transition-colors hover:bg-surface-2 sm:grid-cols-[2.5rem_1fr_7rem_10rem] sm:gap-4"
    >
      <span className="tabular text-sm text-subtle">{rank}</span>

      <span className="min-w-0">
        <span className="flex items-center gap-2">
          <span className="truncate font-medium transition-colors group-hover:text-brand">
            {server.name}
          </span>
          {server.promoted && (
            <span className="shrink-0 rounded-sm bg-accent/15 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-accent uppercase">
              Promoted
            </span>
          )}
        </span>
        <span className="mt-0.5 flex items-center gap-2 text-xs text-subtle">
          <span className="tabular truncate">{server.address}</span>
          {server.country && (
            <span
              className="shrink-0 uppercase"
              title={server.city ? `${server.country.name} — ${server.city}` : server.country.name}
            >
              {server.country.code}
            </span>
          )}
          {server.map && <span className="hidden truncate sm:inline">{server.map}</span>}
        </span>
      </span>

      {/* Status carries a label and a shape, never colour alone. */}
      <span className="hidden items-center gap-2 sm:flex">
        <span
          aria-hidden
          className={`size-1.5 rounded-full ${online ? 'bg-online' : 'bg-offline'}`}
        />
        <span className={`text-xs ${online ? 'text-online' : 'text-subtle'}`}>
          {online ? 'Online' : 'Offline'}
        </span>
      </span>

      <span className="text-right">
        <span className="tabular block text-sm">
          {online ? live.players.toLocaleString('en-US') : '—'}
          <span className="text-subtle">
            {live.max_players > 0 ? ` / ${live.max_players.toLocaleString('en-US')}` : ''}
          </span>
        </span>
        <span aria-hidden className="mt-1 block h-0.5 w-full overflow-hidden rounded-full bg-line">
          <span
            className="block h-full bg-brand transition-[width] duration-300"
            style={{ width: `${Math.round(fill * 100)}%` }}
          />
        </span>
      </span>
    </Link>
  )
}
