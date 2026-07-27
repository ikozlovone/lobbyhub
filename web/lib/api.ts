/**
 * Typed client for the Laravel catalog API.
 *
 * Everything here is server-side by default. Two callers exist:
 *  - cached page shells, which wrap these in `use cache` + cacheLife
 *  - the live layer, which calls `fetchLive` from the browser and never caches
 */

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'

export type ServerStatus = 'online' | 'offline' | 'unknown'

export type Live = {
  status: ServerStatus
  players: number
  max_players: number
  queued: number
  checked_at: string | null
}

export type GameCounters = {
  servers: number
  servers_online: number
  players_online: number
  synced_at: string | null
}

export type Game = {
  slug: string
  name: string
  short_name: string | null
  accent_color: string | null
  icon: string | null
  cover: string | null
  has_versions: boolean
  counters: GameCounters
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

export type GameDetail = Game & {
  facets: { modes: Facet[]; versions: Facet[]; countries: CountryFacet[] }
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
  banner: string | null
  icon: string | null
  votes: number
  rating: number | null
  promoted: boolean
  wiped_at: string | null
  live: Live
}

export type ServerDetail = Server & {
  description: string | null
  host: string
  port: number
  game: Game
  modes?: { slug: string; name: string }[]
  game_version?: { slug: string; name: string } | null
  links: { website: string | null; discord: string | null }
  claimed: boolean
  first_seen_at: string | null
  last_online_at: string | null
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
  const url = `${API_URL}${path}`
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

export type ServerFilters = {
  mode?: string
  version?: string
  country?: string
  status?: 'online'
  sort?: 'players' | 'rank' | 'votes' | 'uptime' | 'wiped' | 'name'
  page?: number
  per_page?: number
}

export async function fetchServers(game: string, filters: ServerFilters = {}) {
  const query = new URLSearchParams(
    Object.entries(filters)
      .filter(([, value]) => value !== undefined && value !== '')
      .map(([key, value]) => [key, String(value)]),
  )

  const suffix = query.toString() ? `?${query}` : ''
  return get<Paginated<Server>>(`/games/${game}/servers${suffix}`)
}

export async function fetchServer(slug: string) {
  const response = await getOrNull<{ data: ServerDetail }>(`/servers/${slug}`)
  return response?.data ?? null
}

export async function fetchHistory(slug: string, range: string) {
  const response = await getOrNull<{ data: History }>(`/servers/${slug}/history?range=${range}`)
  return response?.data ?? null
}

/**
 * The live half of the page. Called from the browser on an interval, so it must
 * never be cached — by Next, by the browser, or by a CDN in between.
 */
export async function fetchLive(slugs: string[]): Promise<(Live & { slug: string })[]> {
  if (slugs.length === 0) return []

  const response = await fetch(`${API_URL}/servers/live?slugs=${slugs.join(',')}`, {
    cache: 'no-store',
    headers: { Accept: 'application/json' },
  })

  if (!response.ok) return []

  return (await response.json()).data
}
