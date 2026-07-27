'use client'

import { useState } from 'react'
import type { ServerDetail } from '@/lib/api'
import { useLive } from './live-provider'

/**
 * Status and the two addresses.
 *
 * They are genuinely different and players confuse them constantly: one is
 * typed into the game client, the other is where the monitor sends its packets.
 * Rust in particular hands out a query port two above the game port.
 */
export function ServerConnection({ server }: { server: ServerDetail }) {
  const live = useLive(server.slug, server.live)
  const online = live.status === 'online'
  const fill = live.max_players > 0 ? Math.min(live.players / live.max_players, 1) : 0

  return (
    <section className="space-y-4 rounded-lg border border-line bg-surface p-4">
      <div className="flex flex-wrap items-center gap-x-8 gap-y-3">
        <div>
          <div className="flex items-center gap-2">
            <span aria-hidden className={`size-2 rounded-full ${online ? 'bg-online' : 'bg-offline'}`} />
            <span className={`text-sm font-medium ${online ? 'text-online' : 'text-subtle'}`}>
              {online ? 'Online' : 'Offline'}
            </span>
          </div>
          <p className="mt-0.5 text-xs text-subtle">
            {live.checked_at
              ? `Checked ${new Date(live.checked_at).toLocaleTimeString('en-US', {
                  hour: '2-digit',
                  minute: '2-digit',
                })}`
              : 'Not checked yet'}
          </p>
        </div>

        <div className="min-w-40">
          <p className="tabular text-lg">
            {online ? live.players.toLocaleString('en-US') : '—'}
            <span className="text-subtle">
              {live.max_players > 0 ? ` / ${live.max_players.toLocaleString('en-US')}` : ''}
            </span>
          </p>
          <p className="text-xs text-subtle">Players</p>
          <span aria-hidden className="mt-1 block h-0.5 overflow-hidden rounded-full bg-line">
            <span
              className="block h-full bg-brand transition-[width] duration-300"
              style={{ width: `${Math.round(fill * 100)}%` }}
            />
          </span>
        </div>

        {live.queued > 0 && <Metric label="In queue" value={live.queued.toLocaleString('en-US')} />}
        {/* Neither of these appears on the competitor's card. */}
        {server.live.uptime !== null && <Metric label="Uptime" value={`${server.live.uptime}%`} />}
        {server.latency_ms !== null && <Metric label="Ping" value={`${server.latency_ms} ms`} />}
      </div>

      <div className="grid gap-2 sm:grid-cols-2">
        {/* A published hostname is friendlier than a raw IP, but it is useless
            without the port — the game client needs host:port either way. */}
        <Address
          label="Players address"
          hint="Type this into the game client"
          value={
            server.connect_hostname
              ? `${server.connect_hostname}:${server.port}`
              : server.connect_address
          }
        />
        <Address
          label="Monitoring address"
          hint="Where we send our checks"
          value={server.query_address}
          muted
        />
      </div>
    </section>
  )
}

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="tabular text-lg">{value}</p>
      <p className="text-xs text-subtle">{label}</p>
    </div>
  )
}

function Address({
  label,
  hint,
  value,
  muted,
}: {
  label: string
  hint: string
  value: string
  muted?: boolean
}) {
  const [copied, setCopied] = useState(false)

  async function copy() {
    await navigator.clipboard.writeText(value)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <div className="rounded-md border border-line bg-bg p-3">
      <div className="flex items-baseline justify-between gap-2">
        <p className="text-xs font-medium">{label}</p>
        <p className="text-[11px] text-subtle">{hint}</p>
      </div>
      <button
        type="button"
        onClick={copy}
        aria-label={`Copy ${label.toLowerCase()} ${value}`}
        className={`tabular mt-1.5 flex w-full cursor-pointer items-center justify-between gap-2 text-sm transition-colors hover:text-brand ${
          muted ? 'text-muted' : ''
        }`}
      >
        <span className="truncate">{value}</span>
        <span className="shrink-0 text-[11px] text-subtle">{copied ? 'copied' : 'copy'}</span>
      </button>
    </div>
  )
}
