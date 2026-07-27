'use client'

import { useState } from 'react'
import type { ServerDetail } from '@/lib/api'
import { useLive } from './live-provider'

/** The live block of a server card: status, players, and the address to copy. */
export function ServerStatusPanel({ server }: { server: ServerDetail }) {
  const live = useLive(server.slug, server.live)
  const online = live.status === 'online'
  const [copied, setCopied] = useState(false)

  async function copyAddress() {
    await navigator.clipboard.writeText(server.address)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <section className="grid gap-3 rounded-lg border border-line bg-surface p-4 sm:grid-cols-[1fr_auto] sm:items-center">
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

        <Metric
          label="Players"
          value={online ? `${live.players.toLocaleString('en-US')} / ${live.max_players.toLocaleString('en-US')}` : '—'}
        />
        {live.queued > 0 && <Metric label="In queue" value={live.queued.toLocaleString('en-US')} />}
        <Metric label="Votes" value={server.votes.toLocaleString('en-US')} />
      </div>

      <button
        type="button"
        onClick={copyAddress}
        className="tabular flex cursor-pointer items-center gap-2 rounded-md border border-line-strong bg-surface-2 px-3 py-2 text-sm transition-colors hover:border-brand hover:text-brand"
        aria-label={`Copy server address ${server.address}`}
      >
        {server.address}
        <span className="text-xs text-subtle">{copied ? 'copied' : 'copy'}</span>
      </button>
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
