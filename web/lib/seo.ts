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

export function canonical(path: string): Metadata['alternates'] {
  return { canonical: path }
}
