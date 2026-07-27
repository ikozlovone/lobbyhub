import Link from 'next/link'
import type { ServerDetail } from '@/lib/api'
import { Icon } from './icons'

/**
 * The facts table.
 *
 * Rows are built from what the server actually published, so a game that says
 * nothing about seeds or entity counts shows fewer rows rather than a column of
 * dashes. Icons are decorative — every row is labelled in words.
 */
export function ServerInformation({ server }: { server: ServerDetail }) {
  const { info } = server

  const rows: { icon: React.ReactNode; label: string; value: React.ReactNode }[] = []

  const push = (icon: React.ReactNode, label: string, value: React.ReactNode) => {
    if (value !== null && value !== undefined && value !== '') rows.push({ icon, label, value })
  }

  push(
    <Icon.globe />,
    'Location',
    server.country ? (
      <>
        <Link
          href={`/games/${server.game.slug}/country/${server.country.slug}`}
          className="cursor-pointer hover:text-brand"
        >
          {server.country.name}
        </Link>
        {server.city && <span className="text-subtle"> → {server.city}</span>}
      </>
    ) : null,
  )
  push(<Icon.map />, 'Map', server.map)
  push(<Icon.ruler />, 'Size', info.map_size ? info.map_size.toLocaleString('en-US') : null)
  push(<Icon.seed />, 'Seed', info.map_seed ? <span className="tabular">{info.map_seed}</span> : null)
  push(<Icon.mode />, 'Mode', info.mode)
  push(<Icon.tag />, 'Version', server.game_version?.name ?? server.version)
  push(<Icon.tag />, 'Build', info.build ? <span className="tabular">{info.build}</span> : null)
  push(
    <Icon.shield />,
    'PvE',
    info.pve === undefined ? null : info.pve ? 'Enabled' : 'Disabled',
  )
  push(<Icon.gauge />, 'FPS', info.fps ? `${info.fps}${info.fps_average ? ` (avg ${info.fps_average})` : ''}` : null)
  push(<Icon.boxes />, 'Entities', info.entities ? info.entities.toLocaleString('en-US') : null)
  push(<Icon.gauge />, 'Uptime', server.live.uptime !== null ? `${server.live.uptime}%` : null)
  push(<Icon.gauge />, 'Ping', server.latency_ms !== null ? `${server.latency_ms} ms` : null)
  push(<Icon.steam />, 'Steam ID', server.steam_id ? <span className="tabular text-xs">{server.steam_id}</span> : null)
  push(<Icon.clock />, 'Last wipe', server.wiped_at ? <Ago stamp={server.wiped_at} /> : null)
  push(<Icon.clock />, 'Running for', info.uptime_seconds ? duration(info.uptime_seconds) : null)
  push(<Icon.clock />, 'Added', server.first_seen_at ? <Ago stamp={server.first_seen_at} /> : null)
  push(<Icon.refresh />, 'Last update', server.live.checked_at ? <Ago stamp={server.live.checked_at} /> : null)

  return (
    <section className="rounded-lg border border-line bg-surface">
      <h2 className="font-display border-b border-line px-4 py-3 text-sm font-bold tracking-wide uppercase">
        Server information
      </h2>
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

function Ago({ stamp }: { stamp: string }) {
  const date = new Date(stamp)
  const minutes = Math.floor((Date.now() - date.getTime()) / 60_000)
  const hours = Math.floor(minutes / 60)
  const days = Math.floor(hours / 24)

  const relative =
    days >= 1
      ? `${days} day${days === 1 ? '' : 's'} ago`
      : hours >= 1
        ? `${hours} hour${hours === 1 ? '' : 's'} ago`
        : minutes >= 1
          ? `${minutes} min ago`
          : 'just now'

  return (
    <time dateTime={stamp} title={date.toLocaleString('en-US')}>
      {relative}
    </time>
  )
}

function duration(seconds: number) {
  const days = Math.floor(seconds / 86_400)
  const hours = Math.floor((seconds % 86_400) / 3_600)

  return days > 0 ? `${days}d ${hours}h` : `${hours}h ${Math.floor((seconds % 3_600) / 60)}m`
}
