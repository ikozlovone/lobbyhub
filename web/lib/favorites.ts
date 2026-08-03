import type { Server } from './api'

/**
 * Client for one account's own list of servers.
 *
 * Everything here is `no-store` and carries the token by hand. This is the one
 * listing on the site that is not shared between visitors, so there is nothing
 * a cache could hold that would be right for the next person to ask — and a
 * favourite that has just been added has to be there when the page reloads.
 */

export type FavoriteGame = {
  slug: string
  name: string
  accent_color: string | null
  icon: string | null
  cover: string | null
  /** Which connect buttons the rows get, exactly as in a game listing. */
  protocol: string
}

export type FavoriteGroup = { game: FavoriteGame; servers: Server[] }

async function send(apiUrl: string, path: string, token: string, method = 'GET'): Promise<Response> {
  return fetch(`${apiUrl}${path}`, {
    method,
    cache: 'no-store',
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  })
}

/**
 * The list, in blocks by game.
 *
 * Throws rather than returning an empty list on failure: "you have no
 * favourites" and "we could not ask" are different sentences, and a page that
 * shows the first when it means the second is telling somebody their list is
 * gone.
 */
export async function fetchFavorites(apiUrl: string, token: string): Promise<FavoriteGroup[]> {
  const response = await send(apiUrl, '/favorites', token)

  if (!response.ok) throw new Error(`Favorites request failed: ${response.status}`)

  return (await response.json()).data
}

export async function addFavorite(apiUrl: string, token: string, slug: string): Promise<boolean> {
  return (await send(apiUrl, `/servers/${slug}/favorite`, token, 'POST')).ok
}

export async function removeFavorite(apiUrl: string, token: string, slug: string): Promise<boolean> {
  return (await send(apiUrl, `/servers/${slug}/favorite`, token, 'DELETE')).ok
}
