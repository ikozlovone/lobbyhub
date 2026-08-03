/**
 * How long a rendered page shell lives.
 *
 * The built-in `minutes` profile these pages used expires after an hour — and
 * expiry does not mean "refetch on the next request", it means the entry is
 * gone. What served the page after that was the partially prerendered fallback:
 * a shell whose dynamic half `next start` never resumed. A direct visit still
 * worked and answered 200, so nothing looked wrong from the outside, while
 * every *click* landed on "This page couldn't load". It came back an hour after
 * each deploy, on every game and server page, until the next build.
 *
 * `expire` is therefore a week. Nothing about freshness changes: `revalidate`
 * is still a minute, and the browser overwrites player counts on top of
 * whatever this serves. What changes is the failure mode — a page nobody has
 * asked for in an hour is now served stale and refreshed behind the request,
 * instead of ceasing to exist.
 *
 * An object rather than a named profile in next.config: the names are not in
 * `cacheLife`'s types, so a profile that had been renamed or removed would fail
 * at runtime rather than in the build.
 */
export const CATALOG_CACHE = {
  /** The client router's own reuse window — matches staleTimes.static. */
  stale: 300,
  /** How often the shell is refreshed in the background. */
  revalidate: 60,
  /** How long a shell nobody has asked for is still worth serving. */
  expire: 604_800,
} as const
