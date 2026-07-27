import type { MetadataRoute } from 'next'
import { getGame, getGames } from '@/lib/data'
import { GAME_INDEX_THRESHOLD, INDEX_THRESHOLD } from '@/lib/seo'

const SITE = process.env.NEXT_PUBLIC_SITE_URL ?? 'http://localhost:3000'

/**
 * Facet pages below the index threshold are deliberately absent: a sitemap that
 * lists thousands of near-empty pages invites exactly the crawl that devalues
 * the domain. They stay reachable through the facet navigation.
 *
 * Server pages are not here yet — at catalog scale they need generateSitemaps()
 * to chunk them under the 50k-URL limit, which is a job for when there are more
 * than a handful of them.
 */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const games = await getGames()

  const entries: MetadataRoute.Sitemap = [
    { url: SITE, changeFrequency: 'hourly', priority: 1 },
  ]

  for (const game of games) {
    // A listed game with no servers yet is a thin page, exactly like an empty
    // facet — browsable, but not something to invite a crawler to.
    if (game.counters.servers < GAME_INDEX_THRESHOLD) continue

    entries.push({
      url: `${SITE}/games/${game.slug}`,
      changeFrequency: 'hourly',
      priority: 0.9,
    })

    const detail = await getGame(game.slug)
    if (!detail) continue

    const facets = [
      ...detail.facets.modes.map((mode) => ({
        path: `/games/${game.slug}/${mode.slug}`,
        count: mode.servers_count,
      })),
      ...detail.facets.versions.map((version) => ({
        path: `/games/${game.slug}/version/${version.slug}`,
        count: version.servers_count,
      })),
      ...detail.facets.countries.map((country) => ({
        path: `/games/${game.slug}/country/${country.slug}`,
        count: country.servers_count,
      })),
    ]

    for (const facet of facets.filter((item) => item.count >= INDEX_THRESHOLD)) {
      entries.push({ url: `${SITE}${facet.path}`, changeFrequency: 'daily', priority: 0.6 })
    }
  }

  return entries
}
