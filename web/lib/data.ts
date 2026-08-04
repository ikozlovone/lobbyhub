import { cache } from 'react'
import { cacheLife, cacheTag } from 'next/cache'
import { CATALOG_CACHE, SITEMAP_CACHE } from './cache'
import {
  SERVER_API_URL,
  fetchCatalogServers,
  fetchGame,
  fetchGames,
  fetchHistory,
  fetchRecentVotes,
  fetchServer,
  fetchServers,
  fetchSitemapServers,
  type CatalogFilters,
  type Facet,
  type Server,
  type ServerFilters,
} from './api'
import { fetchProviders } from './auth'

/**
 * Every read a page shell makes, split by one question: can a visitor tell if
 * this is a minute old?
 *
 * ── Cached ────────────────────────────────────────────────────────────────
 * Navigation chrome. The list of games is the rail on every page and the thing
 * `notFound` is decided against; it changes when an admin adds a game, and
 * FrontendCache already invalidates the `games` tag when one does. Caching it
 * is what keeps a rail on 46 games from costing a request per page view.
 *
 * ── Fresh ─────────────────────────────────────────────────────────────────
 * Everything measured: which servers exist, who is on them, what the monitor
 * last saw. These are read on every request, so a visitor opening a page gets
 * what is in the database at that moment. They are wrapped in React's `cache`
 * so that a page and its `generateMetadata` reading the same thing is one call
 * to Laravel and not two, and in nothing else — the memo lives and dies with
 * the request.
 *
 * The cost is a request per page view, which is why SERVER_API_URL prefers
 * API_URL_INTERNAL: on the production box that is a loopback call to a Laravel
 * already holding the connection pool.
 *
 * Anything in the fresh half must be reached from inside a `<Suspense>`
 * boundary, or it blocks the route's static shell instead of streaming into it.
 */

/**
 * Uncached, explicitly.
 *
 * Cache Components already leave `fetch` uncached outside a `use cache` scope,
 * so this changes nothing today. It is here because the failure it prevents is
 * silent: a caching default reintroduced upstream would make these reads stale
 * again with nothing in this file to show for it.
 */
const FRESH = { cache: 'no-store' } as const satisfies RequestInit

/* ── Cached: navigation chrome ─────────────────────────────────────────── */

/**
 * The game catalog: the rail, the grid, and which slugs are real.
 *
 * The counters ride along on this payload and will be up to a revalidate window
 * old. Nothing shows them from here — the rail only asks whether a game has any
 * servers at all, and the pages that print counters read them fresh. See
 * getGamesWithCounters.
 */
export const getGames = async () => {
  'use cache'
  cacheLife(CATALOG_CACHE)
  cacheTag('games')

  return fetchGames()
}

/**
 * Which sign-in buttons exist. Deployment configuration, not user data — so it
 * is read once on the server and baked into the shell rather than fetched by
 * every visitor before the dialog can open.
 *
 * The failure is deliberately *not* swallowed in here. Catching it inside the
 * cache scope stores the empty list, and an API that was down for the one second
 * this ran would leave the dialog with no buttons at all for the next window —
 * a transient outage turning into a lasting one. Thrown, nothing is cached and
 * the next render tries again; the caller decides what an empty dialog looks
 * like meanwhile.
 */
export const getAuthProviders = async () => {
  'use cache'
  cacheLife(CATALOG_CACHE)

  return fetchProviders(SERVER_API_URL)
}

/* ── Fresh: everything measured ────────────────────────────────────────── */

/**
 * The same catalog, read at request time.
 *
 * Separate from `getGames` because of what it is for: /games prints servers,
 * servers online and players online for all 46 games, and those are the numbers
 * somebody judges the whole site by. The rail keeps the cached copy.
 */
export const getGamesWithCounters = cache(async () => fetchGames(FRESH))

/**
 * One game with its facets.
 *
 * Fresh rather than cached because of the facets: the chip counts, the map and
 * version lists, and the counters in the hero all move whenever the monitor
 * confirms a server or an import lands.
 */
export const getGame = cache(async (slug: string) => fetchGame(slug, FRESH))

export const getServers = cache(async (game: string, filters: ServerFilters = {}) =>
  fetchServers(game, filters, FRESH).catch(() => null),
)

/** The newest additions to one game's catalog. */
export const getLatestServers = cache(async (game: string, limit = 10) =>
  fetchServers(game, { sort: 'newest', per_page: limit }, FRESH).catch(() => null),
)

/**
 * Who voted for what, lately. The rail beside a listing, and the only place a
 * visitor sees that other people are here.
 */
export const getRecentVotes = cache(async (game: string) =>
  fetchRecentVotes(game, FRESH).catch(() => []),
)

/**
 * The home page's cross-game sections.
 *
 * Each returns [] rather than throwing: a section whose request failed leaves
 * an empty array the page hides, instead of taking the whole home page down
 * with it. That is the "одна секция не роняет страницу" rule, enforced here
 * rather than in six try/catch blocks upstream. Each also sits behind its own
 * Suspense boundary on the page, so a slow section delays only itself.
 */
async function catalogSection(filters: CatalogFilters) {
  return fetchCatalogServers(filters, FRESH)
    .then((page) => page.data)
    .catch(() => [] as Server[])
}

export const getPopularServers = cache(async (limit = 8) =>
  catalogSection({ sort: 'players', per_page: limit }),
)

/**
 * Trending, as far as the data honestly allows.
 *
 * There is no growth metric in the schema — no "players this week against last"
 * — so this is `rank_score`, which ServerRanking already computes from recent
 * votes and measured activity. That is a real server-side ordering rather than
 * a shuffle, and it is the closest thing to "moving up" we can currently say.
 * A true trend needs a daily-stats comparison; see the report.
 */
export const getTrendingServers = cache(async (limit = 8) =>
  catalogSection({ sort: 'rank', per_page: limit }),
)

export const getRecentlyAddedServers = cache(async (limit = 8) =>
  catalogSection({ sort: 'newest', per_page: limit }),
)

/**
 * Servers wiped in the last fortnight.
 *
 * Empty for a catalog with no wipe data, which is the point: the section is
 * hidden rather than filled with invented dates.
 */
export const getRecentlyWipedServers = cache(async (limit = 8) =>
  catalogSection({ sort: 'wiped', wiped: 14, per_page: limit }),
)

/** The search results page. */
export const searchServers = cache(async (term: string, limit = 24) => {
  if (term === '') return getPopularServers(limit)

  return catalogSection({ q: term, sort: 'players', per_page: limit })
})

/**
 * One server, in full.
 *
 * Map, FPS, entities, bots, anti-cheat, version, wipe time: every one of them
 * is a measurement that arrives with a poll and then sits in this payload. The
 * live layer in the browser only refreshes the player count, so this read is
 * the only thing standing between a visitor and this morning's snapshot — which
 * is the one thing a monitoring site must not show.
 */
export const getServer = cache(async (slug: string) => fetchServer(slug, FRESH))

export const getHistory = cache(async (slug: string, range: string) =>
  fetchHistory(slug, range, FRESH),
)

/* ── The sitemap, which is cached on purpose ───────────────────────────── */

/**
 * How many server URLs go in one sitemap file.
 *
 * The protocol allows fifty thousand, and this is deliberately under it. Half
 * the ceiling costs one extra file per fifty thousand servers and buys two
 * things: room for the entry to grow — an image or a video per server would
 * double its size, not its count — and a response the frontend assembles from
 * one API answer it can comfortably hold.
 */
export const SERVER_SITEMAP_CHUNK = 25_000

/** Only what the sitemap prints. The rest of a GameDetail is a large thing to cache. */
export type SitemapGame = {
  slug: string
  servers: number
  modes: { slug: string; count: number }[]
  versions: { slug: string; count: number }[]
  countries: { slug: string; count: number }[]
}

/**
 * Every game and every facet under it, in one cached read.
 *
 * A walk over the catalog: one request for the list and one per game for its
 * facets. Sequential rather than at once — this is forty-odd requests, and
 * firing them together at an API that is also serving visitors buys a second
 * of latency on a file nobody is waiting for.
 *
 * The facets are trimmed to slug and count here rather than in the caller,
 * because everything not trimmed is stored: `facets.maps` alone runs to
 * hundreds of entries per game and no route serves it.
 */
export async function getSitemapCatalog(): Promise<SitemapGame[]> {
  'use cache'
  cacheLife(SITEMAP_CACHE)
  cacheTag('games', 'sitemap')

  const games = await fetchGames()
  const catalog: SitemapGame[] = []

  for (const game of games) {
    const detail = await fetchGame(game.slug)

    if (!detail) continue

    const trim = (facets: Facet[]) =>
      facets.map((facet) => ({ slug: facet.slug, count: facet.servers_count }))

    catalog.push({
      slug: detail.slug,
      servers: detail.counters.servers,
      modes: trim(detail.facets.modes),
      versions: trim(detail.facets.versions),
      countries: trim(detail.facets.countries),
    })
  }

  return catalog
}

/** One file's worth of server URLs. `page` is one-based, as the API counts. */
export async function getSitemapServers(page: number) {
  'use cache'
  cacheLife(SITEMAP_CACHE)
  cacheTag('servers', 'sitemap')

  return fetchSitemapServers(page, SERVER_SITEMAP_CHUNK)
}

/**
 * How many files the servers take.
 *
 * Asks for a single row and reads the total off the pagination, so the count
 * costs one small query rather than a walk. At least one either way: a sitemap
 * index pointing at nothing is harder to diagnose than an empty sitemap, and a
 * catalog with no servers in it is a state this site starts in.
 */
export async function countServerSitemaps(): Promise<number> {
  'use cache'
  cacheLife(SITEMAP_CACHE)
  cacheTag('servers', 'sitemap')

  const { meta } = await fetchSitemapServers(1, 1)

  return Math.max(1, Math.ceil(meta.total / SERVER_SITEMAP_CHUNK))
}
