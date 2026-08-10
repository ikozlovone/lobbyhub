/**
 * How long the cached half of the site lives.
 *
 * That half is small on purpose: the game catalog behind the rail, and which
 * sign-in buttons exist. Everything measured — server listings, player counts,
 * facet counts, history — is read at request time instead, so a visitor opening
 * a page sees the database as it is. See lib/data for the split.
 *
 * `expire` is a week, and that is the interesting number. The built-in `minutes`
 * profile these pages used expires after an hour — and expiry does not mean
 * "refetch on the next request", it means the entry is gone. What served the
 * page after that was the partially prerendered fallback: a shell whose dynamic
 * half `next start` never resumed. A direct visit still worked and answered 200,
 * so nothing looked wrong from the outside, while every *click* landed on "This
 * page couldn't load". It came back an hour after each deploy, on every game and
 * server page, until the next build. A week means an entry nobody has asked for
 * is served stale and refreshed behind the request, instead of ceasing to exist.
 *
 * An object rather than a named profile in next.config: the names are not in
 * `cacheLife`'s types, so a profile that had been renamed or removed would fail
 * at runtime rather than in the build.
 */
export const CATALOG_CACHE = {
  /** The client router's own reuse window for this cached content. */
  stale: 300,
  /**
   * How often the catalog is refreshed in the background.
   *
   * Ten minutes, matching the API's own window for the same thing. What this
   * holds is the rail: which games exist and which have servers. A game
   * crossing from zero servers to one is the only change that shows here, and
   * it is not a change anyone is watching for.
   */
  revalidate: 600,
  /** How long a copy nobody has asked for is still worth serving. */
  expire: 604_800,
} as const

/**
 * The sitemap, which is the one thing here that should be cached hard.
 *
 * Nothing else on the site is built from a walk over the whole catalog: every
 * game with its facets, and every server there is, in one answer. Rebuilding
 * that per request would be the most expensive thing the frontend does, on
 * behalf of the only visitor who does not mind waiting and does not come back
 * for an hour anyway.
 *
 * And there is nothing to gain by being quicker. A server added a minute ago
 * appears in the listings immediately, which is how anyone actually finds it; a
 * crawler reads this file on its own schedule, measured in hours at best. An
 * hour late here costs nothing that a fresh copy would have bought.
 */
export const SITEMAP_CACHE = {
  stale: 3_600,
  revalidate: 3_600,
  expire: 604_800,
} as const
