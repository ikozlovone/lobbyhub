import type { Metadata } from 'next'

/**
 * A facet page with almost nothing on it is thin content. Publishing tens of
 * thousands of those (every game × every country, most of them empty) is the
 * fastest way to get a catalog domain devalued, so anything under this bar is
 * reachable and crawlable but explicitly not indexable.
 */
export const INDEX_THRESHOLD = 3

/**
 * A game landing page is a curated entity, not one of thousands of generated
 * filter combinations, so a single server is enough to justify it. Zero servers
 * is still nothing to index.
 */
export const GAME_INDEX_THRESHOLD = 1

export function robotsFor(serversCount: number, threshold = INDEX_THRESHOLD): Metadata['robots'] {
  return serversCount >= threshold ? undefined : { index: false, follow: true }
}

/**
 * Metadata for a page whose subject does not exist.
 *
 * These routes call notFound() and get app/not-found.tsx rendered into them,
 * but they cannot answer 404: Next returns 200 for a *streamed* response, and
 * every one of them streams (see the ◐ rows in the build output). The status
 * line is written before the render reaches the missing record.
 *
 * So the status cannot carry the signal and the metadata has to. Without this a
 * delisted server's URL is a soft 404 — an indexable 200 whose content says
 * "not here" — which is the shape search engines penalise a catalog for. Every
 * generateMetadata that can come up empty returns this.
 */
export function notFoundMetadata(): Metadata {
  return {
    title: 'Page not found',
    robots: { index: false, follow: false },
  }
}

/**
 * Returns the whole `alternates` branch, not the value inside it.
 *
 * Every call site spreads this into a Metadata object — `...canonical('/x')` —
 * and the previous shape spread to `{ canonical: '/x' }`, a key Metadata has no
 * such field for. It type-checked, because a spread of a valid partial always
 * does, and silently emitted no <link rel="canonical"> on any page of the site.
 */
export function canonical(path: string): Pick<Metadata, 'alternates'> {
  return { alternates: { canonical: path } }
}
