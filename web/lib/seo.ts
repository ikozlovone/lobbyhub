import type { Metadata } from 'next'

/**
 * A facet page with almost nothing on it is thin content. Publishing tens of
 * thousands of those (every game × every country, most of them empty) is the
 * fastest way to get a catalog domain devalued, so anything under this bar is
 * reachable and crawlable but explicitly not indexable.
 */
export const INDEX_THRESHOLD = 3

export function robotsFor(serversCount: number): Metadata['robots'] {
  return serversCount >= INDEX_THRESHOLD ? undefined : { index: false, follow: true }
}

export function canonical(path: string): Metadata['alternates'] {
  return { canonical: path }
}
