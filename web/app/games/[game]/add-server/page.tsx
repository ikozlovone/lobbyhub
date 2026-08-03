import type { Metadata } from 'next'
import Link from 'next/link'
import { notFound } from 'next/navigation'
import { cacheLife, cacheTag } from 'next/cache'
import { AddServerForm } from '@/components/add-server-form'
import { Icon } from '@/components/icons'
import { RelativeTime } from '@/components/relative-time'
import type { Game, Server } from '@/lib/api'
import { getGame, getGames, getLatestServers } from '@/lib/data'
import { canonical, notFoundMetadata } from '@/lib/seo'

type Props = { params: Promise<{ game: string }> }

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'

export async function generateStaticParams() {
  const games = await getGames()

  return games.map((game) => ({ game: game.slug }))
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { game: slug } = await params
  const game = await getGame(slug)

  if (!game) return notFoundMetadata()

  return {
    title: `Add a ${game.name} server`,
    description: `Add your ${game.name} server to LobbyHub. One address, verified by our own ${game.monitoring.protocol_label} query — player counts and uptime are measured, not declared.`,
    ...canonical(`/games/${game.slug}/add-server`),
  }
}

export default async function AddGameServerPage({ params }: Props) {
  'use cache'
  cacheLife('minutes')

  const { game: slug } = await params
  // The "latest added" rail on this page is the first place a submission shows
  // up, so a submission has to be able to expire it.
  cacheTag('games', `game:${slug}`)
  const [game, latest] = await Promise.all([getGame(slug), getLatestServers(slug)])

  if (!game) notFound()

  return (
    <div className="space-y-4">
      <section className="rounded-lg border border-line bg-surface p-4">
        <div className="flex items-start gap-3">
          {game.cover ? (
            <img
              src={game.cover}
              alt=""
              width={48}
              height={48}
              className="size-12 shrink-0 rounded-md border border-line object-cover"
            />
          ) : (
            <span
              aria-hidden
              className="size-12 shrink-0 rounded-md"
              style={{ backgroundColor: game.accent_color ?? 'var(--color-line-strong)' }}
            />
          )}

          <div className="min-w-0">
            <h1 className="font-display truncate text-xl font-black tracking-tight sm:text-2xl">
              Add server — {game.name}
            </h1>
            <nav aria-label="Breadcrumb" className="mt-0.5 text-xs text-subtle">
              <Link href="/" className="hover:text-fg">
                LobbyHub
              </Link>
              <span className="mx-1">/</span>
              <Link href={`/games/${game.slug}`} className="hover:text-fg">
                {game.name}
              </Link>
              <span className="mx-1">/</span>
              <Link href="/add-server" prefetch={false} className="hover:text-fg">
                Add server
              </Link>
            </nav>
          </div>
        </div>

        <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1 border-t border-line pt-3 text-sm">
          <Counter label="servers" value={game.counters.servers} />
          <Counter label="servers online" value={game.counters.servers_online} />
          <Counter label="players online" value={game.counters.players_online} />
        </dl>
      </section>

      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div className="min-w-0 space-y-4">
          <section className="rounded-lg border border-line bg-surface p-4">
            <p className="max-w-2xl text-sm text-muted">
              Enter the address players connect to. We query it over{' '}
              {game.monitoring.protocol_label} on the spot, and if the server answers it joins the{' '}
              {game.name} listing immediately. Name, map, version and player counts are read from
              the server itself, so there is nothing else to fill in.
            </p>

            <div className="mt-4">
              <AddServerForm game={game} apiUrl={API_URL} />
            </div>
          </section>

          <Requirements game={game} />
        </div>

        <aside className="rounded-lg border border-line bg-surface">
          <h2 className="font-display border-b border-line px-4 py-3 text-sm font-bold tracking-wide uppercase">
            Latest added servers
          </h2>

          {latest && latest.data.length > 0 ? (
            <ul className="divide-y divide-line">
              {latest.data.map((server) => (
                <LatestRow key={server.slug} server={server} game={game} />
              ))}
            </ul>
          ) : (
            <p className="px-4 py-8 text-center text-sm text-subtle">
              No {game.name} servers yet — yours would be the first.
            </p>
          )}
        </aside>
      </div>
    </div>
  )
}

function Counter({ label, value }: { label: string; value: number }) {
  return (
    <div className="flex items-baseline gap-1.5">
      <dt className="sr-only">{label}</dt>
      <dd className="tabular font-medium">{value.toLocaleString('en-US')}</dd>
      <span className="text-subtle">{label}</span>
    </div>
  )
}

function LatestRow({ server, game }: { server: Server; game: Game }) {
  return (
    <li>
      <Link
        href={`/servers/${server.slug}`}
        className="flex cursor-pointer items-center gap-3 px-4 py-2.5 transition-colors hover:bg-surface-2"
      >
        {game.cover ? (
          <img
            src={game.cover}
            alt=""
            width={32}
            height={32}
            loading="lazy"
            className="size-8 shrink-0 rounded object-cover"
          />
        ) : (
          <span
            aria-hidden
            className="size-8 shrink-0 rounded"
            style={{ backgroundColor: game.accent_color ?? 'var(--color-line-strong)' }}
          />
        )}
        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm font-medium">{server.name}</span>
          <span className="block text-xs text-subtle">
            {server.added_at ? <RelativeTime at={server.added_at} /> : server.address}
          </span>
        </span>
      </Link>
    </li>
  )
}

/**
 * What has to be true before the check can succeed.
 *
 * Every line here is a real cause of a failed submission, and each one is
 * specific to how that game answers: the same "server is offline" message means
 * three different fixes across the three protocols we speak.
 */
function Requirements({ game }: { game: Game }) {
  const { protocol, protocol_label, default_port, default_query_port } = game.monitoring

  const specifics = {
    minecraft: [
      { term: 'enable-status=true', code: true, detail: 'Server List Ping has to be on, or the server answers nothing at all.' },
      { term: 'enable-query', code: true, detail: 'Not required — we use the vanilla status ping, the same one the game client does.' },
      { term: 'A domain works', detail: '_minecraft._tcp SRV records are resolved the way the client resolves them.' },
    ],
    source: [
      { term: 'A2S_INFO', code: true, detail: 'The query port must accept UDP from outside. Most hosts open it by default.' },
      { term: 'sv_setsteamaccount', code: true, detail: 'Not needed for this check, but a server without it drops off Steam lists.' },
      { term: 'Query port', detail: 'Equals the game port on almost every host. Fill the second field only if yours differs.' },
    ],
    fivem: [
      { term: 'sv_endpointPrivacy false', code: true, detail: 'With privacy on the server answers 403, and it cannot be verified.' },
      { term: '/dynamic.json', code: true, detail: 'Must be reachable over HTTP on the game port — that is the endpoint we read.' },
      { term: 'Player names', detail: 'We read counts only. Nicknames from the player list are never stored.' },
    ],
  }[protocol]

  return (
    <section className="rounded-lg border border-line bg-surface p-4">
      <h2 className="font-display text-sm font-bold tracking-wide uppercase">
        Before you add it
      </h2>
      <p className="mt-1 text-sm text-muted">
        A server that is configured wrong is not rejected quietly — the form tells you which check
        failed. These are the ones that matter for {game.name}.
      </p>

      <dl className="mt-3 space-y-2 border-t border-line pt-3 text-sm">
        <Requirement
          icon={<Icon.shield />}
          term={protocol_label}
          detail={`How we query ${game.name}. Default port ${default_port}${
            default_query_port ? `, query port ${default_query_port}` : ''
          }.`}
        />
        {specifics.map((item) => (
          <Requirement key={item.term} icon={<Icon.info />} {...item} />
        ))}
        <Requirement
          icon={<Icon.globe />}
          term="A public address"
          detail="LAN and loopback addresses are refused: nobody else could join them."
        />
      </dl>
    </section>
  )
}

function Requirement({
  icon,
  term,
  detail,
  code,
}: {
  icon: React.ReactNode
  term: string
  detail: string
  /** Mono is for things you paste into a config file, not for prose. */
  code?: boolean
}) {
  return (
    <div className="flex gap-2.5">
      <span aria-hidden className="mt-0.5 shrink-0 text-subtle">
        {icon}
      </span>
      <div className="min-w-0">
        <dt className={`text-sm text-fg ${code ? 'tabular' : 'font-medium'}`}>{term}</dt>
        <dd className="text-sm text-muted">{detail}</dd>
      </div>
    </div>
  )
}
