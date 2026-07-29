'use client'

import Link from 'next/link'
import { useState } from 'react'
import { refreshServer, type ServerDetail } from '@/lib/api'
import { CountryFlag } from './country-flag'
import { Icon } from './icons'
import { useLive, useLivePublish } from './live-provider'
import { RelativeTime } from './relative-time'
import { useToast } from './toast'

/**
 * The facts table.
 *
 * Rows are built from what the server actually published, so a game that says
 * nothing about seeds or entity counts shows fewer rows rather than a column of
 * dashes. Icons are decorative — every row is labelled in words.
 *
 * A client component for two reasons: the player count belongs at the top of
 * the facts and has to come from the live layer, and the refresh button in the
 * header replaces the whole payload with a freshly measured one.
 *
 * A row missing here is a fact we do not have. The measured fields fill in on a
 * server's next poll, so a value added to the monitor appears across the catalog
 * over one polling cycle rather than all at once.
 */
export function ServerInformation({
  server: initial,
  apiUrl,
}: {
  server: ServerDetail
  apiUrl: string
}) {
  // Replaced wholesale by a manual refresh; until then it is the cached shell's
  // copy, which is what the page was rendered from.
  const [server, setServer] = useState(initial)
  const [busy, setBusy] = useState(false)
  const publish = useLivePublish()
  const toast = useToast()

  const { info } = server
  const live = useLive(server.slug, server.live)

  async function recheck() {
    setBusy(true)

    const result = await refreshServer(apiUrl, server.slug)

    setBusy(false)

    if (!result) {
      toast.error('Error', 'Could not reach LobbyHub to refresh this server.')

      return
    }

    setServer(result.server)
    // Into the shared live layer, not just this panel: the connection card
    // above shows the same count, and two answers on one screen is worse than
    // an old one.
    publish(result.server.slug, result.server.live)

    // Saying which of the two happened matters: without it, a refresh that was
    // declined because we checked moments ago looks identical to one that ran
    // and found nothing changed.
    if (result.refreshed) {
      toast.success('Updated', 'We queried the server just now.')
    } else {
      toast.success('Already up to date', 'This server was checked less than a minute ago.')
    }
  }

  const rows: { icon: React.ReactNode; label: string; value: React.ReactNode }[] = []

  const push = (icon: React.ReactNode, label: string, value: React.ReactNode) => {
    if (value !== null && value !== undefined && value !== '') rows.push({ icon, label, value })
  }

  /*
   * Called rather than rendered as a component, so that a missing timestamp is
   * null *here* and `push` can drop the whole row. Wrapped in an element it
   * would always be truthy, and "Last offline" would show up empty for a server
   * that has never been down instead of not showing up at all.
   */
  const stamp = (at: string | null | undefined) => (at ? <RelativeTime at={at} /> : null)

  /*
   * `undefined` has to mean the same as `null` here, and not by accident.
   *
   * A payload rendered before these fields existed simply has no such key, and
   * a page shell outlives a deploy — so during one, `server.bots` is undefined
   * rather than null. Comparing against null alone would send `undefined` down
   * the branch that formats a number, which throws and takes the whole panel
   * with it; for a flag it would quietly print "Disabled" for "we do not know".
   */
  const flag = (value: boolean | null | undefined) =>
    value === null || value === undefined ? null : value ? 'Enabled' : 'Disabled'

  push(
    <Icon.users />,
    'Players online',
    live.status === 'online' ? (
      <span className="tabular">
        <span className="text-online">{live.players.toLocaleString('en-US')}</span>
        <span className="text-subtle">/{live.max_players.toLocaleString('en-US')}</span>
      </span>
    ) : (
      <span className="text-subtle">Offline</span>
    ),
  )
  // Queued players only exist while a server is full; the row disappears with
  // the queue rather than sitting at nought all wipe.
  push(<Icon.users />, 'In queue', live.queued > 0 ? live.queued.toLocaleString('en-US') : null)
  push(<Icon.bot />, 'Bots', server.bots?.toLocaleString('en-US') ?? null)
  push(
    <Icon.globe />,
    'Location',
    server.country ? (
      <span className="flex items-center justify-end gap-2">
        <CountryFlag country={server.country} city={server.city} />
        <Link
          href={`/games/${server.game.slug}/country/${server.country.slug}`}
          className="cursor-pointer hover:text-brand"
        >
          {server.country.name}
        </Link>
        {server.city && <span className="text-subtle">→ {server.city}</span>}
      </span>
    ) : null,
  )
  push(<Icon.language />, 'Server language', server.language?.name ?? null)
  push(<Icon.map />, 'Map', server.map)
  push(<Icon.ruler />, 'Size', info.map_size ? info.map_size.toLocaleString('en-US') : null)
  push(<Icon.seed />, 'Seed', info.map_seed ? <span className="tabular">{info.map_seed}</span> : null)
  push(<Icon.mode />, 'Mode', info.mode)
  push(<Icon.tag />, 'Version', server.game_version?.name ?? server.version)
  push(<Icon.tag />, 'Build', info.build ? <span className="tabular">{info.build}</span> : null)
  push(<Icon.shield />, 'Valve Anti-Cheat', flag(server.vac))
  push(<Icon.shield />, 'PvE', flag(info.pve))
  push(
    <Icon.gauge />,
    'FPS',
    info.fps ? `Current: ${info.fps}${info.fps_average ? `, average: ${info.fps_average}` : ''}` : null,
  )
  push(<Icon.boxes />, 'Entities', info.entities ? info.entities.toLocaleString('en-US') : null)
  push(<Icon.gauge />, 'Uptime', server.live.uptime !== null ? `${server.live.uptime}%` : null)
  push(<Icon.gauge />, 'Ping', server.latency_ms !== null ? `${server.latency_ms} ms` : null)
  push(
    <Icon.steam />,
    'Steam Server ID',
    server.steam_id ? <span className="tabular text-xs">{server.steam_id}</span> : null,
  )
  push(<Icon.clock />, 'Last wipe', stamp(server.wiped_at))
  push(<Icon.clock />, 'Running for', info.uptime_seconds ? duration(info.uptime_seconds) : null)
  push(<Icon.refresh />, 'Last update', stamp(live.checked_at))
  push(<Icon.cloudCheck />, 'Last online', stamp(server.last_online_at))
  push(<Icon.cloudOff />, 'Last offline', stamp(server.last_offline_at))
  push(<Icon.clock />, 'Added', stamp(server.first_seen_at))

  return (
    <section className="rounded-lg border border-line bg-surface">
      <div className="flex items-center justify-between gap-2 border-b border-line px-4 py-2">
        <h2 className="font-display text-sm font-bold tracking-wide uppercase">
          Server information
        </h2>

        <button
          type="button"
          onClick={recheck}
          disabled={busy}
          // The label carries the whole explanation because the control is an
          // icon: there is nothing else for a screen reader to read, and the
          // same sentence is what a mouse gets from the tooltip.
          aria-label="Update the information: query the server again and reload the values below"
          title="Update the information: query the server again and reload the values below"
          className="-mr-1.5 shrink-0 cursor-pointer rounded p-1.5 text-subtle transition-colors hover:bg-surface-2 hover:text-fg disabled:cursor-not-allowed disabled:opacity-60"
        >
          <Icon.refresh className={busy ? 'size-4 shrink-0 animate-spin' : 'size-4 shrink-0'} />
        </button>
      </div>
      <dl className="divide-y divide-line">
        {rows.map((row) => (
          <div key={row.label} className="flex items-center justify-between gap-3 px-4 py-2 text-sm">
            <dt className="flex min-w-0 items-center gap-2 text-subtle">
              <span className="text-line-strong">{row.icon}</span>
              <span className="truncate">{row.label}</span>
            </dt>
            <dd className="shrink-0 text-right">{row.value}</dd>
          </div>
        ))}
      </dl>
    </section>
  )
}

function duration(seconds: number) {
  const days = Math.floor(seconds / 86_400)
  const hours = Math.floor((seconds % 86_400) / 3_600)

  return days > 0 ? `${days}d ${hours}h` : `${hours}h ${Math.floor((seconds % 3_600) / 60)}m`
}
