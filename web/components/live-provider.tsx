'use client'

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { fetchLive, type Live } from '@/lib/api'

/**
 * The live half of every page.
 *
 * The rows arrive correct now — pages read the catalog when the request lands,
 * not from a cached shell — so this is no longer a correction. It is what keeps
 * them correct: a listing left open drifts within minutes, and this re-fetches
 * just the moving numbers for whichever rows are on screen.
 *
 * Which is also why there is no fetch on mount. The server-rendered values are
 * seconds old at that point, and asking again for what we were just handed
 * would be a second request per page view buying nothing.
 */

type LiveMap = Record<string, Live>

const LiveContext = createContext<LiveMap>({})

/**
 * A way in, for measurements that did not come from the interval.
 *
 * The refresh button on the information panel produces a genuinely newer
 * reading than the poller's last one. Without somewhere to put it, the page
 * would show two different player counts side by side — the fresh one in the
 * panel that asked for it, the older one everywhere else.
 */
const PublishContext = createContext<(slug: string, live: Live) => void>(() => {})

export function useLivePublish() {
  return useContext(PublishContext)
}

/** Matches the monitoring cadence for busy servers; polling faster buys nothing. */
const REFRESH_MS = 120_000

export function LiveProvider({ slugs, children }: { slugs: string[]; children: React.ReactNode }) {
  const [live, setLive] = useState<LiveMap>({})

  // The array identity changes on every render; the contents rarely do.
  const key = useMemo(() => slugs.join(','), [slugs])

  useEffect(() => {
    if (!key) return

    let cancelled = false

    const refresh = async () => {
      // Nothing to update while the tab is hidden — and mobile browsers throttle
      // it anyway, so this avoids a burst of requests on return.
      if (document.visibilityState !== 'visible') return

      const rows = await fetchLive(key.split(','))
      if (cancelled) return

      setLive(Object.fromEntries(rows.map(({ slug, ...rest }) => [slug, rest])))
    }

    const timer = setInterval(refresh, REFRESH_MS)
    document.addEventListener('visibilitychange', refresh)

    return () => {
      cancelled = true
      clearInterval(timer)
      document.removeEventListener('visibilitychange', refresh)
    }
  }, [key])

  const publish = useCallback((slug: string, reading: Live) => {
    setLive((current) => ({ ...current, [slug]: reading }))
  }, [])

  return (
    <PublishContext.Provider value={publish}>
      <LiveContext.Provider value={live}>{children}</LiveContext.Provider>
    </PublishContext.Provider>
  )
}

/** Falls back to the server-rendered value until a refresh lands. */
export function useLive(slug: string, fallback: Live): Live {
  return useContext(LiveContext)[slug] ?? fallback
}

/**
 * The reading itself, or nothing at all.
 *
 * `useLive` exists to hand a component something to draw, so it substitutes the
 * value that came with the page. That is wrong for a caller that needs to know
 * whether anything has actually arrived since — the chart appends a point for a
 * new reading, and a fallback would make it append the one it is already
 * drawing, at a time it was not taken.
 */
export function useLiveReading(slug: string): Live | undefined {
  return useContext(LiveContext)[slug]
}
