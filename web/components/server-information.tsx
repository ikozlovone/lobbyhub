import Link from 'next/link'
import type { ServerDetail } from '@/lib/api'

/**
 * The "server information" table.
 *
 * Rows are built from what the server actually published, so a game that says
 * nothing about seeds or entity counts simply shows fewer rows instead of a
 * column of dashes.
 */
export function ServerInformation({ server }: { server: ServerDetail }) {
  const { info } = server

  const rows: [string, React.ReactNode][] = []

  const push = (label: string, value: React.ReactNode) => {
    if (value !== null && value !== undefined && value !== '') rows.push([label, value])
  }

  push(
    'Game',
    <Link href={`/games/${server.game.slug}`} className="cursor-pointer hover:text-brand">
      {server.game.name}
    </Link>,
  )
  push('Mode', info.mode)
  push(
    'Location',
    server.country ? (
      <Link
        href={`/games/${server.game.slug}/country/${server.country.slug}`}
        className="cursor-pointer hover:text-brand"
      >
        {server.country.name}
      </Link>
    ) : null,
  )
  push('Map', server.map)
  push('Map size', info.map_size ? info.map_size.toLocaleString('en-US') : null)
  push('Seed', info.map_seed ? <span className="tabular">{info.map_seed}</span> : null)
  push('Version', server.game_version?.name)
  push('Build', info.build ? <span className="tabular">{info.build}</span> : null)
  push('Reported build', server.version && !info.build ? server.version : null)
  push('PvE', info.pve === undefined ? null : info.pve ? 'Enabled' : 'Disabled')
  push('Entities', info.entities ? info.entities.toLocaleString('en-US') : null)
  push(
    'Server FPS',
    info.fps ? `${info.fps}${info.fps_average ? ` (avg ${info.fps_average})` : ''}` : null,
  )
  push('Last wipe', server.wiped_at ? <RelativeDate stamp={server.wiped_at} /> : null)
  push(
    'Running for',
    info.uptime_seconds ? formatDuration(info.uptime_seconds) : null,
  )
  push('Steam ID', server.steam_id ? <span className="tabular text-xs">{server.steam_id}</span> : null)
  push('First seen', server.first_seen_at ? <RelativeDate stamp={server.first_seen_at} /> : null)
  push('Last check', server.live.checked_at ? <RelativeDate stamp={server.live.checked_at} /> : null)

  return (
    <section className="rounded-lg border border-line bg-surface">
      <h2 className="font-display border-b border-line px-4 py-3 text-sm font-bold tracking-wide uppercase">
        Server information
      </h2>
      <dl className="divide-y divide-line">
        {rows.map(([label, value]) => (
          <div key={label} className="flex justify-between gap-4 px-4 py-2 text-sm">
            <dt className="text-subtle">{label}</dt>
            <dd className="text-right">{value}</dd>
          </div>
        ))}
      </dl>
    </section>
  )
}

function RelativeDate({ stamp }: { stamp: string }) {
  const date = new Date(stamp)
  const days = Math.floor((Date.now() - date.getTime()) / 86_400_000)
  const hours = Math.floor((Date.now() - date.getTime()) / 3_600_000)

  const relative =
    days >= 1 ? `${days} day${days === 1 ? '' : 's'} ago` : hours >= 1 ? `${hours}h ago` : 'just now'

  return (
    <time dateTime={stamp} title={date.toLocaleString('en-US')}>
      {relative}
    </time>
  )
}

function formatDuration(seconds: number) {
  const days = Math.floor(seconds / 86_400)
  const hours = Math.floor((seconds % 86_400) / 3_600)

  return days > 0 ? `${days}d ${hours}h` : `${hours}h ${Math.floor((seconds % 3_600) / 60)}m`
}
