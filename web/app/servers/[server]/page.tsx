import type { Metadata } from 'next'
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { cacheLife } from 'next/cache'
import { LiveProvider } from '@/components/live-provider'
import { PlayersChart } from '@/components/players-chart'
import { ServerConnection } from '@/components/server-connection'
import { ServerInformation } from '@/components/server-information'
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

      {/* The server's own banner, when it publishes one. */}
      {server.media.banner && (
        <div className="overflow-hidden rounded-lg border border-line">
          <img
            src={server.media.banner}
            alt=""
            className="max-h-40 w-full object-cover"
            loading="lazy"
          />
        </div>
      )}

      <header className="flex items-start gap-3">
        {server.media.logo && (
          <img
            src={server.media.logo}
            alt=""
            width={48}
            height={48}
            loading="lazy"
            className="size-12 shrink-0 rounded-md border border-line object-cover"
          />
        )}
        <div className="min-w-0">
          <h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl">
            {server.name}
          </h1>
          {server.motd && server.motd !== server.name && (
            <p className="mt-1 text-sm text-muted">{server.motd}</p>
          )}
        </div>
      </header>

      <LiveProvider slugs={[server.slug]}>
        <ServerConnection server={server} />
      </LiveProvider>

      <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <div className="min-w-0 space-y-6">
          <PlayersChart slug={server.slug} initial={history} apiUrl={API_URL} />

          {server.description && (
            <section className="rounded-lg border border-line bg-surface">
              <h2 className="font-display border-b border-line px-4 py-3 text-sm font-bold tracking-wide uppercase">
                About this server
              </h2>
              <p className="px-4 py-3 text-sm leading-relaxed whitespace-pre-line text-muted">
                {server.description}
              </p>
            </section>
          )}

          {server.modes && server.modes.length > 0 && (
            <section className="rounded-lg border border-line bg-surface p-4">
              <h2 className="font-display mb-3 text-sm font-bold tracking-wide uppercase">Modes</h2>
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
            </section>
          )}
        </div>

        <aside className="space-y-6">
          <ServerInformation server={server} />

          {/* Facepunch renders a map image for every Rust world — the competitor
              page does not show it, and it is the most useful thing on the card. */}
          {server.media.map_image && (
            <section className="overflow-hidden rounded-lg border border-line bg-surface">
              <h2 className="font-display border-b border-line px-4 py-3 text-sm font-bold tracking-wide uppercase">
                Map
              </h2>
              <img src={server.media.map_image} alt="Server map" loading="lazy" className="w-full" />
            </section>
          )}

          {(server.links.website || server.links.discord) && (
            <section className="rounded-lg border border-line bg-surface p-4">
              <h2 className="font-display mb-3 text-sm font-bold tracking-wide uppercase">Links</h2>
              <ul className="space-y-1.5 text-sm">
                {server.links.website && (
                  <li>
                    <a
                      href={server.links.website}
                      rel="nofollow ugc noopener"
                      target="_blank"
                      className="cursor-pointer break-all text-muted transition-colors hover:text-brand"
                    >
                      {server.links.website}
                    </a>
                  </li>
                )}
                {server.links.discord && (
                  <li>
                    <a
                      href={server.links.discord}
                      rel="nofollow ugc noopener"
                      target="_blank"
                      className="cursor-pointer break-all text-muted transition-colors hover:text-brand"
                    >
                      {server.links.discord}
                    </a>
                  </li>
                )}
              </ul>
            </section>
          )}
        </aside>
      </div>

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
