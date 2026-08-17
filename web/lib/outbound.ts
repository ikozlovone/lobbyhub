/**
 * The href for any owner-provided outbound link.
 *
 * Points at our own /go bouncer, which validates the destination and 302s to
 * it under a `noindex, nofollow` header. See app/go/route.ts for the shape of
 * the receiving end and app/robots.ts for why the path is disallowed.
 */
export function outboundHref(url: string): string {
  return `/go?u=${encodeURIComponent(url)}`
}

/**
 * The src for any image a server publishes about itself.
 *
 * Points at our own /img proxy, which fetches the upstream, verifies it is
 * an image and small enough to be one, and serves it back under a noindex
 * header. See app/img/route.ts for the receiving end. Wraps every A2S-
 * published banner, logo and map image so no visitor's browser talks to an
 * anonymous owner's host directly.
 */
export function proxyImage(url: string): string {
  return `/img?u=${encodeURIComponent(url)}`
}
