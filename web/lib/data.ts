import { cacheLife, cacheTag } from 'next/cache'
import { CATALOG_CACHE } from './cache'
import {
  SERVER_API_URL,
  fetchCatalogServers,
  fetchGame,
  fetchGames,
  fetchHistory,
  fetchRecentVotes,
  fetchServer,
  fetchServers,
  type CatalogFilters,
  type Server,
  type ServerFilters,
} from './api'
import { fetchProviders } from './auth'

/**
 * Cached entry points for everything a page shell renders.
 *
 * Both the page body and `generateMetadata` must read through these: data
 * fetched outside a `use cache` scope makes the whole route dynamic, which
 * throws away the prerendering the catalog depends on.
 *
 * Tags are per-entity so Laravel can invalidate one server or one game with
 * revalidateTag instead of waiting out the whole cacheLife window.
 */

export async function getGames() {
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
 * this ran would leave the dialog with no buttons at all for the next hour —
 * a transient outage turning into a lasting one. Thrown, nothing is cached and
 * the next render tries again; the caller decides what an empty dialog looks
 * like meanwhile.
 */
export async function getAuthProviders() {
  'use cache'
  // Minutes, not hours, even though this changes about once a year: when it does
  // change it is because somebody just put a client id in the environment and is
  // reloading the page to see whether it worked. An hour of "no, still nothing"
  // costs more than a request every few minutes ever will.
  cacheLife(CATALOG_CACHE)

  return fetchProviders(SERVER_API_URL)
}

export async function getGame(slug: string) {
  'use cache'
  // Minutes, not hours: this object carries the facet chips, and their counts
  // move whenever the monitor confirms a server or an import lands. Tag
  // invalidation only fires when somebody submits one, so an hour here is an
  // hour of a listing that has moved on without its own filters.
  cacheLife(CATALOG_CACHE)
  cacheTag('games', `game:${slug}`)

  return fetchGame(slug)
}

export async function getServers(game: string, filters: ServerFilters = {}) {
  'use cache'
  /*
   * The live layer overwrites the player counts in the browser, so those can go
   * stale here freely. Membership cannot: an owner who has just added a server
   * goes straight to the listing to look for it, and at `hours` it would not be
   * there — through no fault of the submission, which published it instantly.
   *
   * Revalidating `game:{game}` on submission would be better than a short
   * window, and the tag is here for it. Nothing calls it yet; see open questions
   * in Мониторинг.md.
   */
  cacheLife(CATALOG_CACHE)
  cacheTag(`game:${game}`, 'servers')

  return fetchServers(game, filters).catch(() => null)
}

/**
 * The newest additions to one game's catalog.
 *
 * Kept apart from `getServers` because it is the one listing whose whole point
 * is being current — an hour-old copy of "just added" says the opposite of what
 * the panel is there to say.
 */
export async function getLatestServers(game: string, limit = 10) {
  'use cache'
  cacheLife(CATALOG_CACHE)
  cacheTag(`game:${game}`, 'servers')

  return fetchServers(game, { sort: 'newest', per_page: limit }).catch(() => null)
}

/**
 * Who voted for what, lately. The rail beside a listing, and the only place a
 * visitor sees that other people are here — so it is cached in minutes, not
 * hours, or it would show the same four names all day.
 */
export async function getRecentVotes(game: string) {
  'use cache'
  cacheLife(CATALOG_CACHE)
  cacheTag(`game:${game}`, 'votes')

  return fetchRecentVotes(game).catch(() => [])
}

/**
 * The home page's cross-game sections.
 *
 * Each returns [] rather than throwing, and each is cached separately: a
 * section whose request failed leaves an empty array the page hides, instead of
 * taking the whole home page down with it. That is the "одна секция не роняет
 * страницу" rule, enforced here rather than in six try/catch blocks upstream.
 *
 * Minutes, not hours: these are the counts a visitor judges the whole site by,
 * and the live layer only refreshes rows already on screen — it cannot add the
 * server that became busiest since the shell was built.
 */
async function catalogSection(filters: CatalogFilters) {
  return fetchCatalogServers(filters)
    .then((page) => page.data)
    .catch(() => [] as Server[])
}

export async function getPopularServers(limit = 8) {
  'use cache'
  cacheLife(CATALOG_CACHE)
  cacheTag('servers')

  return catalogSection({ sort: 'players', per_page: limit })
}

/**
 * Trending, as far as the data honestly allows.
 *
 * There is no growth metric in the schema — no "players this week against last"
 * — so this is `rank_score`, which ServerRanking already computes from recent
 * votes and measured activity. That is a real server-side ordering rather than
 * a shuffle, and it is the closest thing to "moving up" we can currently say.
 * A true trend needs a daily-stats comparison; see the report.
 */
export async function getTrendingServers(limit = 8) {
  'use cache'
  cacheLife(CATALOG_CACHE)
  cacheTag('servers')

  return catalogSection({ sort: 'rank', per_page: limit })
}

export async function getRecentlyAddedServers(limit = 8) {
  'use cache'
  cacheLife(CATALOG_CACHE)
  cacheTag('servers')

  return catalogSection({ sort: 'newest', per_page: limit })
}

/**
 * Servers wiped in the last fortnight.
 *
 * Empty for a catalog with no wipe data, which is the point: the section is
 * hidden rather than filled with invented dates.
 */
export async function getRecentlyWipedServers(limit = 8) {
  'use cache'
  cacheLife(CATALOG_CACHE)
  cacheTag('servers')

  return catalogSection({ sort: 'wiped', wiped: 14, per_page: limit })
}

/**
 * The search results page.
 *
 * Not cached by term: the space of queries is unbounded and mostly one-shot, so
 * a cache entry per term buys a hit rate near zero and holds every typo anyone
 * ever submitted. The empty term is the browsable "all servers" listing, and
 * that one is worth caching — hence the split.
 */
export async function searchServers(term: string, limit = 24) {
  if (term === '') return getPopularServers(limit)

  return catalogSection({ q: term, sort: 'players', per_page: limit })
}

export async function getServer(slug: string) {
  'use cache'
  /*
   * Minutes, not hours.
   *
   * The live layer only refreshes player counts. Everything else the detail
   * page shows — map, FPS, entities, bots, anti-cheat, version, wipe time — is
   * a measurement that arrives with each poll and then sits in this payload
   * until it expires. At `hours` a server could be re-queried a dozen times
   * while the page kept showing what it looked like this morning, which is the
   * one thing a monitoring site must not do.
   *
   * The right answer is for Laravel to call revalidateTag when it writes a
   * poll — the tag below exists for exactly that and nothing calls it yet. Until
   * then the window is the guarantee, so it has to be a short one.
   */
  cacheLife(CATALOG_CACHE)
  cacheTag(`server:${slug}`)

  return fetchServer(slug)
}

export async function getHistory(slug: string, range: string) {
  'use cache'
  cacheLife(CATALOG_CACHE)
  cacheTag(`server:${slug}`)

  return fetchHistory(slug, range)
}
