import { cacheLife, cacheTag } from 'next/cache'
import { fetchGame, fetchGames, fetchHistory, fetchServer, fetchServers, type ServerFilters } from './api'

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

export async function getGame(slug: string) {
  'use cache'
  cacheLife('hours')
  cacheTag('games', `game:${slug}`)

  return fetchGame(slug)
}

export async function getServers(game: string, filters: ServerFilters = {}) {
  'use cache'
  // Listings hold player counts the live layer overwrites in the browser, so
  // they can sit in cache far longer than the numbers stay accurate.
  cacheLife('hours')
  cacheTag(`game:${game}`, 'servers')

  return fetchServers(game, filters).catch(() => null)
}

export async function getServer(slug: string) {
  'use cache'
  cacheLife('hours')
  cacheTag(`server:${slug}`)

  return fetchServer(slug)
}

export async function getHistory(slug: string, range: string) {
  'use cache'
  cacheLife('minutes')
  cacheTag(`server:${slug}`)

  return fetchHistory(slug, range)
}
