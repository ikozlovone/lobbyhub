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
