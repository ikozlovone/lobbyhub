import { revalidateTag } from 'next/cache'

/**
 * Cache invalidation, called by the API when the catalog changes.
 *
 * Page shells here are cached for minutes, which is the right window for
 * numbers that drift on their own — but not for a change somebody just made and
 * came straight back to look at. Laravel calls this the moment it publishes a
 * server, and the pages carrying those tags are rebuilt on the next request
 * instead of waiting the window out.
 *
 * Unconfigured, this route refuses rather than pretending: a revalidation
 * endpoint that anyone may call is a way to make a site rebuild every page it
 * has, over and over.
 */
export async function POST(request: Request) {
  const secret = process.env.REVALIDATE_SECRET

  if (!secret) {
    return Response.json({ error: 'Revalidation is not configured.' }, { status: 503 })
  }

  if (request.headers.get('x-revalidate-secret') !== secret) {
    return Response.json({ error: 'Not authorised.' }, { status: 401 })
  }

  const body = await request.json().catch(() => null)
  const tags: unknown = body?.tags

  if (!Array.isArray(tags) || tags.some((tag) => typeof tag !== 'string')) {
    return Response.json({ error: 'Expected { tags: string[] }.' }, { status: 422 })
  }

  // Capped: this is a loop over whatever the caller sent, and the caller is on
  // the other side of a network boundary.
  //
  // `expire: 0`, not the usual "max". "max" is stale-while-revalidate: the next
  // visitor is served the old page and the rebuild happens behind them — which
  // is wrong here, because that visitor is the person who just added the server
  // and came back to look for it. They would be shown the listing without it,
  // exactly as before any of this existed. The docs name this case: an external
  // system calling a route handler that needs the data gone now.
  //
  // The cost is that whoever arrives first waits for a render. Submissions are
  // rare and rate-limited, so that is a handful of renders a day.
  for (const tag of tags.slice(0, 32)) {
    revalidateTag(tag, { expire: 0 })
  }

  return Response.json({ revalidated: tags.slice(0, 32) })
}
