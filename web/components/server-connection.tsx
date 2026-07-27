'use client'

import { useState } from 'react'
import type { ServerDetail } from '@/lib/api'
import { Icon } from './icons'
import { useLive } from './live-provider'

/**
 * The two addresses, side by side.
 *
 * They are genuinely different and players confuse them constantly: one goes
 * into the game client, the other is where our monitor sends its packets. Rust
 * commonly hands out a query port two above the game port.
 */
export function ServerConnection({ server }: { server: ServerDetail }) {
  const live = useLive(server.slug, server.live)

  const connect = server.connect_hostname
    ? `${server.connect_hostname}:${server.port}`
    : server.connect_address

  return (
    <section className="rounded-lg border border-line bg-surface">
      <h2 className="font-display border-b border-line px-4 py-3 text-sm font-bold tracking-wide uppercase">
        Server connection
      </h2>

      <div className="space-y-2 p-3">
        <Address label="Players address" value={connect} game={server.game.slug} />
        <Address label="Monitoring address" value={server.query_address} muted />
      </div>

      <div className="flex items-center justify-between gap-3 border-t border-line px-4 py-2.5 text-sm">
        <span className="flex items-center gap-2">
          <span
            aria-hidden
            className={`size-1.5 rounded-full ${live.status === 'online' ? 'bg-online' : 'bg-offline'}`}
          />
          <span className={live.status === 'online' ? 'text-online' : 'text-subtle'}>
            {live.status === 'online' ? 'Online' : 'Offline'}
          </span>
        </span>
        <span className="tabular">
          <span className={live.status === 'online' ? '' : 'text-subtle'}>{live.players}</span>
          <span className="text-subtle">/{live.max_players}</span>
        </span>
      </div>
    </section>
  )
}

function Address({
  label,
  value,
  muted,
  game,
}: {
  label: string
  value: string
  muted?: boolean
  game?: string
}) {
  const [copied, setCopied] = useState(false)

  async function copy() {
    await navigator.clipboard.writeText(value)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <div className="flex items-center gap-2 rounded-md border border-line bg-bg px-3 py-2">
      <span className="min-w-0 flex-1">
        <span className="block text-[11px] text-subtle">{label}</span>
        <span className={`tabular block text-xs break-all ${muted ? 'text-muted' : ''}`}>{value}</span>
      </span>

      <button
        type="button"
        onClick={copy}
        aria-label={`Copy ${label.toLowerCase()}`}
        title={copied ? 'Copied' : 'Copy'}
        className={`cursor-pointer rounded p-1.5 transition-colors ${
          copied ? 'text-brand' : 'text-subtle hover:bg-surface-2 hover:text-fg'
        }`}
      >
        <Icon.copy />
      </button>

      {/* Rust registers a steam:// handler that joins a server directly. Only
          offered where the client actually supports it. */}
      {game === 'rust' && (
        <a
          href={`steam://connect/${value}`}
          aria-label="Connect through Steam"
          title="Connect"
          className="cursor-pointer rounded p-1.5 text-subtle transition-colors hover:bg-surface-2 hover:text-brand"
        >
          <Icon.play />
        </a>
      )}
    </div>
  )
}
