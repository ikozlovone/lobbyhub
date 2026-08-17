import type { MetadataRoute } from 'next'
import { countServerSitemaps } from '@/lib/data'

const SITE = process.env.NEXT_PUBLIC_SITE_URL ?? 'http://localhost:3000'

/**
 * Names every sitemap, because there is more than one and nothing else points
 * at the rest.
 *
 * The servers are chunked into files of their own — see app/servers/sitemap.ts
 * — and Next writes no index tying them together. robots.txt is the index:
 * several `Sitemap:` lines is what the protocol allows and what the engines
 * read, and it is the file they fetch first regardless.
 *
 * Async, and therefore counting the chunks on every request. That is one small
 * cached read; getting it wrong the other way would mean a robots.txt that
 * names two files while the catalog has grown into three, with the servers in
 * the third submitted nowhere at all.
 */
export default async function robots(): Promise<MetadataRoute.Robots> {
  const chunks = await countServerSitemaps()

  return {
    rules: {
      userAgent: '*',
      allow: '/',
      disallow: [
        // Sorted and paginated variants are the same listings in another order.
        '/*?sort=',
        '/*?page=',
        // The outbound bouncer for owner-typed URLs — see app/go/route.ts.
        // Blocked at the source so a crawler never follows the redirect and
        // never associates our host with the destinations it lands on.
        '/go',
        // The image proxy for server-published banners and maps — see
        // app/img/route.ts. Same reason: Google Image Search would otherwise
        // catalog these under our domain despite our not choosing them.
        '/img',
      ],
    },
    sitemap: [
      `${SITE}/sitemap.xml`,
      ...Array.from({ length: chunks }, (_, id) => `${SITE}/servers/sitemap/${id}.xml`),
    ],
  }
}
