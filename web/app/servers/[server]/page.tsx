import type { Metadata } from 'next'
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { cacheLife } from 'next/cache'
import { Icon } from '@/components/icons'
import { LiveProvider } from '@/components/live-provider'
import { PlayersChart } from '@/components/players-chart'
import { RankBar } from '@/components/rank-bar'
import { ServerConnection } from '@/components/server-connection'
import { ServerInformation } from '@/components/server-information'
import { ShareBlock } from '@/components/share-block'
import { VotePanel } from '@/components/vote-panel'
import { getHistory, getServer } from '@/lib/data'
import { canonical } from '@/lib/seo'

type Props = { params: Promise<{ server: string }> }

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'
const SITE_URL = process.env.NEXT_PUBLIC_SITE_URL ?? 'http://localhost:3000'

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
    <LiveProvider slugs={[server.slug]}>
      <div className="space-y-4">
        {/* Header card: identity and the section nav, mirroring the tab strip on
            the pages this competes with. Every tab here is a real section. */}
        <section className="rounded-lg border border-line bg-surface p-4">
          <div className="flex items-start gap-3">
            {server.media.logo ? (
              <img
                src={server.media.logo}
                alt=""
                width={48}
                height={48}
                loading="lazy"
                className="size-12 shrink-0 rounded-md border border-line object-cover"
              />
            ) : server.game.cover ? (
              <img
                src={server.game.cover}
                alt=""
                width={48}
                height={48}
                loading="lazy"
                className="size-12 shrink-0 rounded-md border border-line object-cover"
              />
            ) : (
              <span
                aria-hidden
                className="size-12 shrink-0 rounded-md"
                style={{ backgroundColor: server.game.accent_color ?? 'var(--color-line-strong)' }}
              />
            )}
            <div className="min-w-0">
              <h1 className="font-display truncate text-xl font-black tracking-tight sm:text-2xl">
                {server.name}
              </h1>
              <nav aria-label="Breadcrumb" className="mt-0.5 text-xs text-subtle">
                <Link href="/" className="hover:text-fg">
                  LobbyHub
                </Link>
                <span className="mx-1">/</span>
                <Link href={`/games/${server.game.slug}`} className="hover:text-fg">
                  {server.game.name}
                </Link>
                <span className="mx-1">/</span>
                <span className="text-muted">{server.name}</span>
              </nav>
            </div>
          </div>

          <div className="mt-3 flex flex-wrap gap-1.5 border-t border-line pt-3 text-sm">
            <Tab href="#about" icon={<Icon.info />} label="About server" active />
            <Tab href="#statistics" icon={<Icon.chart />} label="Statistics" />
            <Tab
              href="#ranking"
              icon={<Icon.star />}
              label="Points and ranking"
              badge={server.standing.points.toLocaleString('en-US')}
            />
          </div>
        </section>

        <div className="grid gap-4 lg:grid-cols-[21rem_1fr]">
          {/* Facts on the left, content on the right: the facts are what people
              come to copy, and they read better in a narrow column. */}
          <div className="space-y-4">
            <ServerConnection server={server} />
            <ServerInformation server={server} />
            <ShareBlock url={`${SITE_URL}/servers/${server.slug}`} name={server.name} />
          </div>

          <div className="min-w-0 space-y-4">
            <div id="ranking" className="scroll-mt-20">
              <RankBar standing={server.standing} />
            </div>

            {server.media.banner && (
              <div className="overflow-hidden rounded-lg border border-line">
                <img
                  src={server.media.banner}
                  alt=""
                  loading="lazy"
                  className="max-h-72 w-full object-cover"
                />
              </div>
            )}

            {server.description && (
              <section id="about" className="scroll-mt-20 rounded-lg border border-line bg-surface p-4">
                <p className="text-sm leading-relaxed whitespace-pre-line text-muted">
                  {server.description}
                </p>
              </section>
            )}

            <div id="statistics" className="scroll-mt-20">
              <PlayersChart slug={server.slug} initial={history} apiUrl={API_URL} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <VotePanel slug={server.slug} apiUrl={API_URL} />

              <div className="space-y-4">
                {server.media.map_image && (
                  <section className="overflow-hidden rounded-lg border border-line bg-surface">
                    <h2 className="font-display border-b border-line px-4 py-3 text-sm font-bold tracking-wide uppercase">
                      Map
                    </h2>
                    <img src={server.media.map_image} alt="Server map" loading="lazy" className="w-full" />
                  </section>
                )}

                {server.modes && server.modes.length > 0 && (
                  <section className="rounded-lg border border-line bg-surface p-4">
                    <h2 className="font-display mb-3 text-sm font-bold tracking-wide uppercase">
                      Modes
                    </h2>
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

                {(server.links.website || server.links.discord) && (
                  <section className="rounded-lg border border-line bg-surface p-4">
                    <h2 className="font-display mb-3 flex items-center gap-2 text-sm font-bold tracking-wide uppercase">
                      <Icon.link /> Links
                    </h2>
                    <ul className="space-y-1.5 text-sm">
                      {[server.links.website, server.links.discord]
                        .filter((href): href is string => Boolean(href))
                        .map((href) => (
                          <li key={href}>
                            <a
                              href={href}
                              rel="nofollow ugc noopener"
                              target="_blank"
                              className="cursor-pointer break-all text-muted transition-colors hover:text-brand"
                            >
                              {href}
                            </a>
                          </li>
                        ))}
                    </ul>
                  </section>
                )}
              </div>
            </div>
          </div>
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
    </LiveProvider>
  )
}

function Tab({
  href,
  icon,
  label,
  badge,
  active,
}: {
  href: string
  icon: React.ReactNode
  label: string
  badge?: string
  active?: boolean
}) {
  return (
    <a
      href={href}
      className={`flex cursor-pointer items-center gap-2 rounded-md px-3 py-1.5 transition-colors ${
        active ? 'bg-brand/15 text-brand' : 'text-muted hover:bg-surface-2 hover:text-fg'
      }`}
    >
      {icon}
      {label}
      {badge && (
        <span className="tabular rounded bg-surface-2 px-1.5 py-0.5 text-[11px] text-muted">
          {badge}
        </span>
      )}
    </a>
  )
}
