/**
 * Typed client for the Laravel catalog API.
 *
 * Everything here is server-side by default. Two callers exist:
 *  - cached page shells, which wrap these in `use cache` + cacheLife
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
  icon: string | null
  cover: string | null
  has_versions: boolean
  counters: GameCounters
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

export type Server = {
  slug: string
  name: string
  motd: string | null
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

export type ServerDetail = Server & {
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

async function get<T>(path: string, init?: RequestInit): Promise<T> {
  const url = `${SERVER_API_URL}${path}`
  const response = await fetch(url, {
    ...init,
    headers: { Accept: 'application/json', ...init?.headers },
  })

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

export async function fetchGames() {
  return (await get<{ data: Game[] }>('/games')).data
}

export async function fetchGame(slug: string) {
  const response = await getOrNull<{ data: GameDetail }>(`/games/${slug}`)
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

/**
 * A vote, as much of it as is public: the nickname the voter published so an
 * owner can reward them, and nothing that links two votes to one person.
 */
export type RecentVote = {
  nickname: string | null
  at: string | null
  server: { slug: string; name: string }
}

export async function fetchRecentVotes(game: string) {
  const response = await getOrNull<{ data: RecentVote[] }>(`/games/${game}/votes`)
  return response?.data ?? []
}

export async function fetchServer(slug: string) {
  const response = await getOrNull<{ data: ServerDetail }>(`/servers/${slug}`)
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

export async function fetchHistory(slug: string, range: string) {
  const response = await getOrNull<{ data: History }>(`/servers/${slug}/history?range=${range}`)
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
