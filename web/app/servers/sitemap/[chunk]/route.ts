import { countServerSitemaps, getSitemapServers } from '@/lib/data'

const SITE = process.env.NEXT_PUBLIC_SITE_URL ?? 'http://localhost:3000'

/** Matches SITEMAP_CACHE's revalidate; there is nothing to gain by disagreeing. */
const MAX_AGE = 3600

/**
 * Every server page, in files of SERVER_SITEMAP_CHUNK, at /servers/sitemap/0.xml.
 *
 * Written by hand rather than with Next's sitemap convention, and that is the
 * whole point of this file. `generateSitemaps` decides how many files exist at
 * build time: ask for a chunk it did not enumerate and the answer is 404. For a
 * catalog that grows between deploys that is a trap with a date on it — the day
 * the twenty-five-thousand-and-first server is listed, robots.txt starts naming
 * a second file that does not exist, and every server past the boundary is
 * submitted nowhere at all until somebody happens to rebuild. Measured on the
 * version that used the convention: /servers/sitemap/1.xml answered 404 while
 * /0.xml answered 200.
 *
 * A route handler has no such enumeration. Any chunk that has servers in it is
 * served, from the moment it has them.
 *
 * The main sitemap keeps the convention, because it is one file and one file
 * cannot run out.
 *
 * Which servers: the ones the site itself considers real. A row we have written
 * down but never reached has a page that answers and no listing linking to it,
 * and submitting that is asking for an orphaned thin page to be indexed. The
 * API's SitemapController draws that line with `verified`.
 */
export async function GET(_request: Request, { params }: { params: Promise<{ chunk: string }> }) {
  const { chunk } = await params

  // `0.xml`, and nothing else — not `0`, not `00`, not `+1`. A sitemap
  // reachable at several addresses is duplicate content aimed at a crawler.
  const match = /^(0|[1-9]\d*)\.xml$/.exec(chunk)

  if (!match) return notFound()

  const id = Number(match[1])
  const chunks = await countServerSitemaps()

  // Out of range answers 404 rather than an empty urlset: an empty file is a
  // claim that this part of the catalog is empty, which is a different thing to
  // tell a crawler, and a wrong one.
  if (id >= chunks) return notFound()

  const { data } = await getSitemapServers(id + 1)

  const urls = data.map((server) => {
    const parts = [`<loc>${escape(`${SITE}/servers/${server.slug}`)}</loc>`]

    /*
     * Omitted rather than invented when the API has nothing to give. No lastmod
     * means "no claim"; a made-up one — today's date, say — is a claim that is
     * wrong on most of twenty thousand URLs, and a field a crawler catches
     * lying is one it stops reading for the whole domain.
     */
    if (server.lastmod) parts.push(`<lastmod>${escape(server.lastmod)}</lastmod>`)

    /*
     * Hourly is honest here in a way it would not be on a static page: the
     * player count, the map and the status on these really do move that often.
     * Priority puts them below a game page and above a facet — the most
     * numerous pages on the site and individually the least important, but also
     * what somebody searching for a server by name is trying to reach.
     */
    parts.push('<changefreq>hourly</changefreq>', '<priority>0.7</priority>')

    return `  <url>${parts.join('')}</url>`
  })

  const body = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${urls.join('\n')}
</urlset>
`

  return new Response(body, {
    headers: {
      'Content-Type': 'application/xml; charset=utf-8',
      /*
       * Said out loud for whatever sits in front of this. The data behind it is
       * held for an hour either way (SITEMAP_CACHE), but a CDN has no way to
       * know that, and this is the one response on the site big enough that
       * serving it from the edge is worth a header.
       */
      'Cache-Control': `public, max-age=0, s-maxage=${MAX_AGE}, stale-while-revalidate=${MAX_AGE}`,
    },
  })
}

function notFound() {
  return new Response('Not found', { status: 404, headers: { 'Content-Type': 'text/plain' } })
}

/**
 * Slugs are minted from names people choose, so nothing here is assumed safe.
 * `&` goes first or it would re-escape the escapes.
 */
function escape(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;')
}
