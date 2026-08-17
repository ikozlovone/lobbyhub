import { NextRequest, NextResponse } from 'next/server'

/**
 * Same-origin bounce for every outgoing link on a user-generated surface.
 *
 * The anchor a crawler sees is `/go?u=...` on our host, not the destination.
 * robots.txt disallows this path, so search engines never fetch it and never
 * associate our domain with wherever an owner has pointed their link. The
 * headers below repeat the same rule for anything that ignores robots, and
 * strip the referrer so the destination cannot see which of our pages the
 * click came from.
 *
 * The redirect target is validated to `http(s)://<host>` — no `javascript:`,
 * no `file:`, no schemeless input that a browser might resolve relative to
 * the current origin. An invalid `u` gets a 400, deliberately, so a broken
 * link is not silently swallowed as a homepage bounce.
 */

const HEADERS = {
  'X-Robots-Tag': 'noindex, nofollow',
  'Referrer-Policy': 'no-referrer',
  'Cache-Control': 'private, no-store',
} as const

export function GET(request: NextRequest) {
  const destination = safeDestination(request.nextUrl.searchParams.get('u'))

  if (destination === null) {
    return new NextResponse('Invalid destination', { status: 400, headers: HEADERS })
  }

  return NextResponse.redirect(destination, { status: 302, headers: HEADERS })
}

function safeDestination(raw: string | null): string | null {
  if (!raw) return null

  let parsed: URL
  try {
    parsed = new URL(raw)
  } catch {
    return null
  }

  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return null
  if (!parsed.hostname) return null

  return parsed.toString()
}
