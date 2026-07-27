import type { Metadata } from 'next'
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { cacheLife } from 'next/cache'
import { LiveProvider } from '@/components/live-provider'
import { PlayersChart } from '@/components/players-chart'
import { ServerStatusPanel } from '@/components/server-status-panel'
import { getHistory, getServer } from '@/lib/data'
import { canonical } from '@/lib/seo'

type Props = { params: Promise<{ server: string }> }

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { server: slug } = await params
  const server = await getServer(slug)

  if (!server) return {}

  const description = server.motd
    ? `${server.motd.slice(0, 140)} — live players, uptime and history on LobbyHub.`
    : `${server.name}: live player count, uptime and history for this ${server.game.name} server.`

  return {
    title: `${server.name} — ${server.game.name} server`,
    description,
    ...canonical(`/servers/${server.slug}`),
  }
}

export default async function ServerPage({ params }: Props) {
  'use cache'
  cacheLife('hours')

  const { server: slug } = await params
  const [server, history] = await Promise.all([getServer(slug), getHistory(slug, '24h')])

  if (!server) notFound()

  return (
    <div className="space-y-6">
      <nav aria-label="Breadcrumb" className="text-xs text-subtle">
        <Link href="/" className="hover:text-fg">
          Home
        </Link>
        <span className="mx-1.5">/</span>
        <Link href={`/games/${server.game.slug}`} className="hover:text-fg">
          {server.game.name}
        </Link>
        <span className="mx-1.5">/</span>
        <span className="text-muted">{server.name}</span>
      </nav>

      <header className="space-y-2">
        <h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl">
          {server.name}
        </h1>
        {server.motd && <p className="max-w-3xl text-sm text-muted">{server.motd}</p>}
      </header>

      <LiveProvider slugs={[server.slug]}>
        <ServerStatusPanel server={server} />
      </LiveProvider>

      <PlayersChart slug={server.slug} initial={history} apiUrl={API_URL} />

      <section className="grid gap-3 sm:grid-cols-2">
        <DetailCard title="Details">
          <Detail label="Game" value={server.game.name} href={`/games/${server.game.slug}`} />
          {server.game_version && <Detail label="Version" value={server.game_version.name} />}
          {server.version && <Detail label="Reported build" value={server.version} mono />}
          {server.map && <Detail label="Map" value={server.map} />}
          {server.country && (
            <Detail
              label="Location"
              value={server.country.name}
              href={`/games/${server.game.slug}/country/${server.country.slug}`}
            />
          )}
          {server.wiped_at && (
            <Detail
              label="Last wipe"
              value={`${new Date(server.wiped_at).toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
              })} (${daysSince(server.wiped_at)} days ago)`}
            />
          )}
        </DetailCard>

        <DetailCard title="Modes">
          {server.modes && server.modes.length > 0 ? (
            <ul className="flex flex-wrap gap-1.5">
              {server.modes.map((mode) => (
                <li key={mode.slug}>
                  <Link
                    href={`/games/${server.game.slug}/${mode.slug}`}
                    className="inline-block cursor-pointer rounded border border-line px-2 py-1 text-xs text-muted transition-colors hover:border-line-strong hover:text-fg"
                  >
                    {mode.name}
                  </Link>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-subtle">Not categorised yet.</p>
          )}
        </DetailCard>
      </section>

      {/* Structured data: this is a game server listing, and search engines
          should read it as one rather than guessing from the markup. */}
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{
          __html: JSON.stringify({
            '@context': 'https://schema.org',
            '@type': 'GameServer',
            name: server.name,
            url: `/servers/${server.slug}`,
            playersOnline: server.live.players,
            game: { '@type': 'VideoGame', name: server.game.name },
          }),
        }}
      />
    </div>
  )
}

function DetailCard({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="rounded-lg border border-line bg-surface p-4">
      <h2 className="mb-3 font-display text-xs font-bold tracking-wide text-muted uppercase">
        {title}
      </h2>
      <div className="space-y-2">{children}</div>
    </div>
  )
}

function Detail({
  label,
  value,
  href,
  mono,
}: {
  label: string
  value: string
  href?: string
  mono?: boolean
}) {
  const text = <span className={mono ? 'tabular' : undefined}>{value}</span>

  return (
    <div className="flex justify-between gap-4 text-sm">
      <span className="text-subtle">{label}</span>
      {href ? (
        <Link href={href} className="cursor-pointer text-right transition-colors hover:text-brand">
          {text}
        </Link>
      ) : (
        <span className="text-right">{text}</span>
      )}
    </div>
  )
}

function daysSince(stamp: string) {
  return Math.floor((Date.now() - new Date(stamp).getTime()) / 86_400_000)
}
