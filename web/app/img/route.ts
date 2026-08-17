import { NextRequest, NextResponse } from 'next/server'

/**
 * Same-origin image bouncer for every URL a server publishes about itself.
 *
 * A banner, logo or map image the frontend used to render as `<img src=...>`
 * would send the visitor's browser to whatever host an anonymous owner had
 * typed into their A2S rules. That is how our IP shows up in some Rust
 * server's access log, how a 302 to malware turns our page into the referrer,
 * and how Google Image Search associates our domain with images we neither
 * chose nor host. This route fetches the upstream from our origin instead,
 * verifies the response is actually an image and small enough to be one, and
 * serves it back under a noindex header. robots.txt disallows the path so no
 * crawler follows it, and the destination — kept in the `u` param — stays out
 * of anything we serve as HTML.
 *
 * The URL is validated to `http(s)://<domain>` — no `javascript:`, no
 * `file:`, and no IP hosts (a raw IP is the shape SSRF attempts take against
 * private ranges). Content-type must start with `image/`, the payload is
 * capped at MAX_BYTES, and the upstream fetch has a hard timeout so a
 * hostile server that answers slowly cannot tie up a route worker.
 */
export const dynamic = 'force-dynamic'

const BASE_HEADERS = {
  'X-Robots-Tag': 'noindex, nofollow',
  'Referrer-Policy': 'no-referrer',
} as const

const MAX_BYTES = 5 * 1024 * 1024
const TIMEOUT_MS = 5000

export async function GET(request: NextRequest) {
  const destination = safeImageUrl(request.nextUrl.searchParams.get('u'))

  if (destination === null) {
    return new NextResponse('Invalid image URL', {
      status: 400,
      headers: { ...BASE_HEADERS, 'Cache-Control': 'private, no-store' },
    })
  }

  const controller = new AbortController()
  const timeout = setTimeout(() => controller.abort(), TIMEOUT_MS)

  try {
    const upstream = await fetch(destination, {
      signal: controller.signal,
      // Follow redirects; the destination-check ran on the URL we were given.
      // A redirect chain that lands on private space would still be blocked
      // by any egress rules on the host, but this proxy is not the last line.
      redirect: 'follow',
      headers: { 'User-Agent': 'lobbyhub-image-proxy' },
    })

    if (!upstream.ok) {
      return new NextResponse('Upstream error', {
        status: 502,
        headers: { ...BASE_HEADERS, 'Cache-Control': 'private, max-age=60' },
      })
    }

    const contentType = upstream.headers.get('content-type') ?? ''
    if (!contentType.startsWith('image/')) {
      return new NextResponse('Not an image', {
        status: 415,
        headers: { ...BASE_HEADERS, 'Cache-Control': 'private, max-age=300' },
      })
    }

    const declared = upstream.headers.get('content-length')
    if (declared !== null && Number.parseInt(declared, 10) > MAX_BYTES) {
      return new NextResponse('Image too large', {
        status: 413,
        headers: { ...BASE_HEADERS, 'Cache-Control': 'private, max-age=300' },
      })
    }

    const buffer = await upstream.arrayBuffer()
    if (buffer.byteLength > MAX_BYTES) {
      return new NextResponse('Image too large', {
        status: 413,
        headers: { ...BASE_HEADERS, 'Cache-Control': 'private, max-age=300' },
      })
    }

    return new NextResponse(buffer, {
      status: 200,
      headers: {
        ...BASE_HEADERS,
        'Content-Type': contentType,
        // A day on the visitor, a week on the shared cache in front of us —
        // a server's banner does not change often, and the URL keys the
        // cache so a new URL replaces the old entry on its own.
        'Cache-Control': 'public, max-age=86400, s-maxage=604800, stale-while-revalidate=604800',
      },
    })
  } catch {
    return new NextResponse('Fetch failed', {
      status: 502,
      headers: { ...BASE_HEADERS, 'Cache-Control': 'private, max-age=60' },
    })
  } finally {
    clearTimeout(timeout)
  }
}

function safeImageUrl(raw: string | null): string | null {
  if (!raw) return null

  let parsed: URL
  try {
    parsed = new URL(raw)
  } catch {
    return null
  }

  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return null
  if (!parsed.hostname) return null
  if (isIpHost(parsed.hostname)) return null

  return parsed.toString()
}

/**
 * Both address families, checked without a DNS lookup. Legitimate CDNs come
 * as domains, so blocking IP hosts folds every SSRF vector aimed at a private
 * range — 127/8, 10/8, 169.254/16 — into one rejection before the fetch is
 * made. A DNS-rebinding attack against a domain that resolves to a private
 * address would need a separate egress guard at the host or network level.
 */
function isIpHost(hostname: string): boolean {
  if (/^\d+\.\d+\.\d+\.\d+$/.test(hostname)) return true
  if (hostname.includes(':')) return true
  return false
}
