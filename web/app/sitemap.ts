import type { MetadataRoute } from 'next'
import { getSitemapCatalog } from '@/lib/data'
import { GAME_INDEX_THRESHOLD, INDEX_THRESHOLD } from '@/lib/seo'

const SITE = process.env.NEXT_PUBLIC_SITE_URL ?? 'http://localhost:3000'

/**
 * Everything the site has except the servers themselves, which are their own
 * files under /servers/sitemap — there are tens of thousands of those and they
 * have to be chunked. robots.txt names all of them.
 *
 * One rule decides what is in here: a URL belongs in a sitemap only if it is
 * indexable. Submitting a page that carries `noindex` is a contradiction — the
 * file says "crawl this and index it", the page says the opposite — so the
 * thresholds below are the same ones robotsFor() applies to the pages
 * themselves, and /favorites and the auth callback are absent because they set
 * noindex on themselves.
 *
 * The whole thing is one cached read. See SITEMAP_CACHE for why this is the one
 * place on the site that caches hard.
 */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const catalog = await getSitemapCatalog()

  const entries: MetadataRoute.Sitemap = [
    { url: SITE, changeFrequency: 'hourly', priority: 1 },
    // The catalog. Second only to the home page: every game page hangs off it.
    { url: `${SITE}/games`, changeFrequency: 'hourly', priority: 0.9 },
    // The bare listing only. ?q= variants are noindex — see the search page.
    { url: `${SITE}/search`, changeFrequency: 'hourly', priority: 0.6 },
    // The submission funnel: this page carries every per-game form under it.
    { url: `${SITE}/add-server`, changeFrequency: 'weekly', priority: 0.8 },
    // Indexable but unimportant: nobody searches for it, and a legal page a
    // crawler cannot find is one an app store or a payment processor reads as
    // missing.
    { url: `${SITE}/terms`, changeFrequency: 'yearly', priority: 0.2 },
    { url: `${SITE}/privacy`, changeFrequency: 'yearly', priority: 0.2 },
  ]

  for (const game of catalog) {
    // A listed game with no servers yet is a thin page, exactly like an empty
    // facet — browsable, but not something to invite a crawler to.
    if (game.servers < GAME_INDEX_THRESHOLD) continue

    entries.push({
      url: `${SITE}/games/${game.slug}`,
      changeFrequency: 'hourly',
      priority: 0.9,
    })

    /*
     * The per-game form, which was missing.
     *
     * It is a real indexable page with its own title and canonical, and it is
     * the one people arrive at from outside — "add my rust server" is a search
     * somebody makes, and it should land on the Rust form rather than on the
     * game picker. Weekly, because nothing on it moves except the rail of
     * latest additions down the side.
     */
    entries.push({
      url: `${SITE}/games/${game.slug}/add-server`,
      changeFrequency: 'weekly',
      priority: 0.5,
    })

    const facets = [
      ...game.modes.map((mode) => ({
        path: `/games/${game.slug}/${mode.slug}`,
        count: mode.count,
      })),
      ...game.versions.map((version) => ({
        path: `/games/${game.slug}/version/${version.slug}`,
        count: version.count,
      })),
      ...game.countries.map((country) => ({
        path: `/games/${game.slug}/country/${country.slug}`,
        count: country.count,
      })),
    ]

    for (const facet of facets.filter((item) => item.count >= INDEX_THRESHOLD)) {
      entries.push({ url: `${SITE}${facet.path}`, changeFrequency: 'daily', priority: 0.6 })
    }
  }

  return entries
}
