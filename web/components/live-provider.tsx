'use client'

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { fetchLive, type Live } from '@/lib/api'

/**
 * The live half of every page.
 *
 * Page shells are cached for hours, so the player counts baked into them go
 * stale within minutes. This provider re-fetches just the moving numbers and
 * hands them to whichever rows are on screen. Server-rendered values remain the
 * fallback, so the page is correct before hydration and merely fresher after.
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
