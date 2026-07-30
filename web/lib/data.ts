import { cacheLife, cacheTag } from 'next/cache'
import {
  SERVER_API_URL,
  fetchGame,
  fetchGames,
  fetchHistory,
  fetchRecentVotes,
  fetchServer,
  fetchServers,
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
  cacheLife('minutes')
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
  cacheLife('minutes')

  return fetchProviders(SERVER_API_URL)
}

export async function getGame(slug: string) {
  'use cache'
  cacheLife('hours')
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
  cacheLife('minutes')
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
  cacheLife('minutes')
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
  cacheLife('minutes')
  cacheTag(`game:${game}`, 'votes')

  return fetchRecentVotes(game).catch(() => [])
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
  cacheLife('minutes')
  cacheTag(`server:${slug}`)

  return fetchServer(slug)
}

export async function getHistory(slug: string, range: string) {
  'use cache'
  cacheLife('minutes')
  cacheTag(`server:${slug}`)

  return fetchHistory(slug, range)
}
