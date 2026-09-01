/**
 * Typed client for the Laravel catalog API.
 *
 * Three callers, and lib/data is where the first two are sorted out:
 *  - the cached catalog chrome, which wraps these in `use cache` + cacheLife
 *  - page reads, which pass `{ cache: 'no-store' }` and run per request
 *  - the live layer, which calls `fetchLive` from the browser and never caches
 */

/** The address browsers use. Compiled into the bundle, so it must be public. */
export const PUBLIC_API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'

/**
 * Where *this* process reaches the API.
 *
 * The same address for the browser, and for a server that has no better one.
 * When the API happens to live on the same machine, `API_URL_INTERNAL` points at
 * it directly, and every render this process does — plus the whole production
 * build, which prerenders game pages by fetching the catalog — stops leaving the
 * box to talk to something already on it.
 *
 * That is not only a saved hop. With TLS terminated at the CDN, the public
 * address resolves to the edge, so a build on the server depends on the server
 * being reachable *from the internet* — DNS propagated, proxy healthy, origin
 * accepting connections. None of that has anything to do with compiling a page,
 * and all of it fails the build when it is not yet true.
 *
 * Named for the side it belongs to on purpose: anything a page hands to a client
 * component must be PUBLIC_API_URL, or a visitor's browser is told to fetch the
 * catalog from their own machine.
 *
 * Unset, nothing changes.
 */
export const SERVER_API_URL =
  typeof window === 'undefined'
    ? (process.env.API_URL_INTERNAL ?? PUBLIC_API_URL)
    : PUBLIC_API_URL

export type ServerStatus = 'online' | 'offline' | 'unknown'

export type Live = {
  status: ServerStatus
  players: number
  max_players: number
  queued: number
  checked_at: string | null
}

/** The card carries uptime too; the polling endpoint deliberately does not. */
export type ServerLive = Live & { uptime: number | null }

export type GameCounters = {
  servers: number
  servers_online: number
  players_online: number
  synced_at: string | null
}

export type SteamCounters = {
  players_online: number
  players_peak: number
  /** Position in Steam's own top 100, or null when the game is below it. */
  chart_rank: number | null
  synced_at: string | null
}

export type Monitoring = {
  protocol: 'minecraft' | 'source' | 'fivem'
  protocol_label: string
  default_port: number
  default_query_port: number | null
}

export type Game = {
  slug: string
  name: string
  short_name: string | null
  aliases: string[]
  accent_color: string | null
  /** The thumbnail, drawn at 28px in the rail and beside a favourite. */
  icon: string | null
  /** The card in the games list. */
  cover: string | null
  /** The banner across a game page. Falls back to `cover` where unset. */
  hero: string | null
  has_versions: boolean
  counters: GameCounters
  /**
   * What Steam says about the game itself — everybody playing it anywhere,
   * not the players our monitor found on its servers. `synced_at` is null for
   * a game the collector has not reached, which is not the same as nobody
   * playing it.
   */
  steam: SteamCounters
  monitoring: Monitoring
  seo: { title: string | null; description: string | null }
  description?: string | null
}

export type Facet = {
  slug: string
  name: string
  servers_count: number
  players_online?: number
}

export type CountryFacet = Facet & { code: string }

/** Somewhere the game lives that is not this site: its own page, its docs. */
export type GameLink = { name: string; url: string }

export type GameDetail = Game & {
  /** Editorial, set per game in the admin. Most games have none. */
  links?: GameLink[]
  facets: {
    /** Availability and capacity buckets — see ServerListing::STATUSES. */
    statuses: Facet[]
    modes: Facet[]
    versions: Facet[]
    countries: CountryFacet[]
    /** `slug` is the map name verbatim: free text is what the filter takes. */
    maps: Facet[]
  }
}

export type Country = { code: string; name: string; slug: string }

/** Which game a row belongs to. Only the catalog-wide listing carries it. */
export type ServerGame = { slug: string; name: string; protocol: Monitoring['protocol'] }

export type Server = {
  slug: string
  name: string
  motd: string | null
  /** Present on cross-game listings only — inside a game the caller knows already. */
  game?: ServerGame
  address: string
  map: string | null
  version: string | null
  country?: Country | null
  city?: string | null
  banner: string | null
  icon: string | null
  votes: number
  rating: number | null
  promoted: boolean
  wiped_at: string | null
  added_at: string | null
  live: ServerLive
}

export type ServerInfo = {
  mode?: string
  map_size?: number
  map_seed?: number
  entities?: number
  fps?: number
  fps_average?: number
  pve?: boolean
  build?: string
  uptime_seconds?: number
  connect_hostname?: string
}

export type Standing = {
  position: number
  total: number
  points: number
  leader_points: number
}

export type ServerMedia = {
  banner?: string
  logo?: string
  map_image?: string
  map_file?: string
}

/**
 * `game` is omitted from the base before being redeclared: an intersection
 * cannot replace a property, it combines them, so `Server & { game: Game }`
 * quietly means "a Game that also has ServerGame's protocol field" — a shape
 * the API never sends. Omit makes the detail's own `game` the only one.
 */
export type ServerDetail = Omit<Server, 'game'> & {
  description: string | null
  host: string
  port: number
  connect_address: string
  query_address: string
  connect_hostname: string | null
  steam_id: string | null
  /** Source only; null where the protocol has no such notion. */
  bots: number | null
  vac: boolean | null
  /** Inferred from what the owner wrote, not reported by any protocol. */
  language: { code: string; name: string } | null
  info: ServerInfo
  media: ServerMedia
  standing: Standing
  details_synced_at: string | null
  latency_ms: number | null
  game: Game
  modes?: { slug: string; name: string }[]
  game_version?: { slug: string; name: string } | null
  links: { website: string | null; discord: string | null }
  claimed: boolean
  first_seen_at: string | null
  last_online_at: string | null
  last_offline_at: string | null
}

export type HistoryPoint = {
  at: string
  players: number
  online?: boolean
  peak?: number
  uptime?: number
}

export type History = {
  range: string
  source: 'raw' | 'daily'
  points: HistoryPoint[]
}

export type Paginated<T> = {
  data: T[]
  meta: { current_page: number; last_page: number; total: number; per_page: number }
}

class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly url: string,
  ) {
    super(`API ${status} for ${url}`)
  }
}

/**
 * A read that never got an answer: refused, reset, DNS, or still open when the
 * clock below ran out.
 *
 * Separate from ApiError because it is a different thing to fix — that one is
 * the API saying no, this one is not reaching it — and because of where it
 * tends to surface. Inside a `use cache` scope during a build, a fetch that
 * hangs is reported by Next as "Filling a cache during prerender timed out,
 * likely because request-specific arguments … were used inside use cache",
 * which sends you looking for a `cookies()` call that was never there. The
 * address and the reason belong in the build log instead.
 */
class ApiUnreachable extends Error {
  constructor(
    public readonly url: string,
    cause: unknown,
  ) {
    super(`Could not reach the API at ${url} — ${describe(cause)}`, { cause })
  }
}

/**
 * Undici says `TypeError: fetch failed` for every network failure there is and
 * keeps which one it was in a nested cause. That code is the whole diagnosis —
 * ECONNREFUSED is nothing listening, ECONNRESET is something that hung up
 * (an origin answering `return 444` to a request that did not come through the
 * CDN), ENOTFOUND is DNS — so it goes in the message rather than staying one
 * `.cause` deeper than anything prints.
 */
function describe(cause: unknown): string {
  if (!(cause instanceof Error)) return String(cause)

  const inner = cause.cause
  const code =
    inner && typeof inner === 'object' && 'code' in inner ? String(inner.code) : null

  return code ? `${cause.name}: ${cause.message} (${code})` : `${cause.name}: ${cause.message}`
}

/**
 * How long one read may take before it is called a failure.
 *
 * Above anything the API does when it is well: the slowest read here is a
 * sitemap page of 25 000 rows, which is a couple of seconds on the loopback
 * address a production render uses and under ten over the internet. So this is
 * not a latency budget — it is the line past which "slow" is better described
 * as "not answering", and it is well under the deadline a prerender gives up
 * at, so the error that reaches the log is this one and not that one.
 */
const REQUEST_TIMEOUT_MS = 15_000

async function get<T>(path: string, init?: RequestInit): Promise<T> {
  const url = `${SERVER_API_URL}${path}`
  let response: Response

  try {
    response = await fetch(url, {
      // Before the spread, so a caller that has its own signal keeps it.
      signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
      ...init,
      headers: { Accept: 'application/json', ...init?.headers },
    })
  } catch (cause) {
    throw new ApiUnreachable(url, cause)
  }

  if (!response.ok) {
    throw new ApiError(response.status, url)
  }

  return response.json() as Promise<T>
}

/** Returns null on 404 so pages can call notFound() instead of crashing. */
async function getOrNull<T>(path: string, init?: RequestInit): Promise<T | null> {
  try {
    return await get<T>(path, init)
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) {
      return null
    }
    throw error
  }
}

/**
 * `init` on every reader below, for the same reason `fetchServers` has always
 * had one: the catalog is read from two kinds of scope now. Cached chrome calls
 * these bare, and the pages that must show what is in the database *right now*
 * pass `{ cache: 'no-store' }` through — see FRESH in lib/data.
 */
export async function fetchGames(init?: RequestInit) {
  return (await get<{ data: Game[] }>('/games', init)).data
}

export async function fetchGame(slug: string, init?: RequestInit) {
  const response = await getOrNull<{ data: GameDetail }>(`/games/${slug}`, init)
  return response?.data ?? null
}

/** The status chips, and the only values the API will accept for `status`. */
export type StatusFilter = 'online' | 'players' | 'full' | 'empty' | 'offline'

export type ServerSort = 'players' | 'rank' | 'votes' | 'uptime' | 'wiped' | 'name' | 'newest'

export type ServerFilters = {
  mode?: string
  version?: string
  country?: string
  status?: StatusFilter
  map?: string
  q?: string
  sort?: ServerSort
  page?: number
  per_page?: number
}

/**
 * A page of a game's listing.
 *
 * Called from two places with opposite needs: page shells read it inside a
 * `use cache` scope, and the browser re-reads it whenever a filter changes —
 * hence `init`, which the client passes `{ cache: 'no-store' }` through so the
 * Refresh button actually refreshes.
 */
export async function fetchServers(game: string, filters: ServerFilters = {}, init?: RequestInit) {
  const query = new URLSearchParams(
    Object.entries(filters)
      .filter(([, value]) => value !== undefined && value !== '')
      .map(([key, value]) => [key, String(value)]),
  )

  const suffix = query.toString() ? `?${query}` : ''
  return get<Paginated<Server>>(`/games/${game}/servers${suffix}`, init)
}

export type CatalogFilters = {
  game?: string
  country?: string
  status?: StatusFilter
  q?: string
  sort?: ServerSort
  /** Days back to count as recently wiped. Omitted means no wipe filter at all. */
  wiped?: number
  page?: number
  per_page?: number
}

/**
 * The listing across every game — what the home page's sections are made of.
 *
 * One request per section rather than one per game: see ServerController's
 * `catalog`, which exists for exactly this.
 */
export async function fetchCatalogServers(filters: CatalogFilters = {}, init?: RequestInit) {
  const query = new URLSearchParams(
    Object.entries(filters)
      .filter(([, value]) => value !== undefined && value !== '')
      .map(([key, value]) => [key, String(value)]),
  )

  const suffix = query.toString() ? `?${query}` : ''
  return get<Paginated<Server>>(`/servers${suffix}`, init)
}

/**
 * A vote, as much of it as is public: the nickname the voter published so an
 * owner can reward them, and nothing that links two votes to one person.
 */
export type RecentVote = {
  nickname: string | null
  at: string | null
  server: { slug: string; name: string }
}

export async function fetchRecentVotes(game: string, init?: RequestInit) {
  const response = await getOrNull<{ data: RecentVote[] }>(`/games/${game}/votes`, init)
  return response?.data ?? []
}

/** One game's line in the player-count chart. */
export type ChartRow = {
  /** Position in this chart, which ranks only the games this site carries. */
  position: number
  slug: string
  name: string
  icon: string | null
  accent_color: string | null
  steam_appid: number
  players: number
  peak: number
  /** Where Steam puts it in its own top 100, or null when it is below it. */
  steam_rank: number | null
  servers: number
  servers_online: number
  /** Players on the servers we monitor — the other number entirely. */
  server_players: number
}

export type Chart = {
  data: ChartRow[]
  meta: {
    games: number
    players: number
    charted: number
    synced_at: string | null
  }
}

export async function fetchCharts(init?: RequestInit) {
  return get<Chart>('/charts', init)
}

/** A game's player count over time, from the ten-minute samples in ClickHouse. */
export type GamePlayers = {
  range: string
  source: 'raw' | 'daily'
  /** When this game's history starts — the tables begin when the collector did. */
  recording_since: string | null
  points: { at: string; players: number; peak?: number }[]
}

export async function fetchGamePlayers(slug: string, range: string, init?: RequestInit) {
  const response = await getOrNull<{ data: GamePlayers }>(
    `/games/${slug}/players?range=${range}`,
    init,
  )

  return response?.data ?? null
}

export async function fetchServer(slug: string, init?: RequestInit) {
  const response = await getOrNull<{ data: ServerDetail }>(`/servers/${slug}`, init)
  return response?.data ?? null
}

/**
 * Ask for this server to be queried again, now.
 *
 * The API answers with the whole detail payload either way — if it queried, the
 * numbers are seconds old; if the server was checked too recently to be worth
 * disturbing again, they are the ones already on file. `refreshed` says which,
 * so the panel can tell the visitor what actually happened.
 */
export async function refreshServer(
  apiUrl: string,
  slug: string,
): Promise<{ server: ServerDetail; refreshed: boolean } | null> {
  try {
    const response = await fetch(`${apiUrl}/servers/${slug}/refresh`, {
      method: 'POST',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    })

    if (!response.ok) return null

    const payload = await response.json()

    return { server: payload.data, refreshed: payload.refreshed === true }
  } catch {
    return null
  }
}

export async function fetchHistory(slug: string, range: string, init?: RequestInit) {
  const response = await getOrNull<{ data: History }>(
    `/servers/${slug}/history?range=${range}`,
    init,
  )
  return response?.data ?? null
}

/**
 * What came of a submission.
 *
 * Three outcomes, not two. "Already in the catalog" is not a failure — the
 * address is valid, the server is real, and the thing the submitter wanted is
 * already true. Folding it in with genuine errors is what made the form shout
 * in red at someone whose only mistake was not knowing we had it.
 */
export type Submission =
  | { status: 'created'; server: Server; message: string }
  | { status: 'listed'; server: { slug: string; name: string }; message: string }
  | { status: 'error'; error: string }

/**
 * Add a server. Called from the browser: verification queries the address the
 * visitor typed, so this is the one endpoint that must never be prefetched or
 * replayed from a cache.
 *
 * Failures are values, not exceptions — "we could not reach your server" is the
 * form's most common outcome and belongs next to the field, not in an error
 * boundary.
 */
export async function submitServer(
  apiUrl: string,
  game: string,
  form: { address: string; query_port: number | null },
): Promise<Submission> {
  let response: Response

  try {
    response = await fetch(`${apiUrl}/games/${game}/servers`, {
      method: 'POST',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(form),
    })
  } catch {
    return { status: 'error', error: 'Could not reach LobbyHub. Check your connection and try again.' }
  }

  const payload = await response.json().catch(() => null)

  if (response.status === 201 && payload?.data) {
    return { status: 'created', server: payload.data, message: payload.message ?? '' }
  }

  if (response.status === 409 && payload?.data) {
    return {
      status: 'listed',
      server: payload.data,
      message: payload.message ?? 'This server is already in the catalog.',
    }
  }

  return {
    status: 'error',
    // Laravel puts the useful sentence under the field; `message` repeats it for
    // everything else, including the rate limiter.
    error:
      payload?.errors?.address?.[0] ??
      payload?.message ??
      'Could not add the server. Try again in a moment.',
  }
}

/**
 * One server as the sitemap needs it: where it is, and when what the page says
 * about it last changed. See the API's SitemapController for why `lastmod` is
 * not the last time we queried the thing.
 */
export type SitemapServer = { slug: string; lastmod: string | null }

/**
 * A page of every server URL there is.
 *
 * Its own endpoint rather than `fetchCatalogServers`, which cannot answer this:
 * the listing is capped at a hundred pages of a hundred rows, so a sitemap
 * built on it would stop at ten thousand servers without saying so.
 */
/**
 * A message from the contact form. The API validates each field and answers
 * with either `sent` or `error`; `fieldErrors` carries the per-field wording
 * the form paints next to each input, and `error` is the sentence a toast
 * announces. The rate limiter answers with a plain `message`, which is what
 * `error` falls back to when nothing under `errors` matched.
 */
export type ContactOutcome =
  | { status: 'sent' }
  | {
      status: 'error'
      error: string
      fieldErrors?: Partial<Record<'name' | 'email' | 'subject' | 'message', string>>
    }

export async function sendContactMessage(
  apiUrl: string,
  form: { name: string; email: string; subject: string; message: string },
): Promise<ContactOutcome> {
  let response: Response

  try {
    response = await fetch(`${apiUrl}/contact`, {
      method: 'POST',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(form),
    })
  } catch {
    return { status: 'error', error: 'Could not reach LobbyHub. Check your connection and try again.' }
  }

  const payload = await response.json().catch(() => null)

  if (response.status === 200) {
    return { status: 'sent' }
  }

  const errors = payload?.errors as Record<string, string[]> | undefined
  const fieldErrors = errors
    ? Object.fromEntries(
        Object.entries(errors).map(([field, messages]) => [field, messages[0]]),
      )
    : undefined

  return {
    status: 'error',
    error:
      payload?.message ??
      (errors ? 'Please check the highlighted fields.' : 'Could not send the message. Try again in a moment.'),
    fieldErrors,
  }
}

export async function fetchSitemapServers(page: number, perPage: number, init?: RequestInit) {
  return get<Paginated<SitemapServer>>(
    `/sitemap/servers?page=${page}&per_page=${perPage}`,
    init,
  )
}

/**
 * The live half of the page. Called from the browser on an interval, so it must
 * never be cached — by Next, by the browser, or by a CDN in between.
 */
export async function fetchLive(slugs: string[]): Promise<(Live & { slug: string })[]> {
  if (slugs.length === 0) return []

  const response = await fetch(`${SERVER_API_URL}/servers/live?slugs=${slugs.join(',')}`, {
    cache: 'no-store',
    headers: { Accept: 'application/json' },
  })

  if (!response.ok) return []

  return (await response.json()).data
}
