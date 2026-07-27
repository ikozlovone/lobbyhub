'use client'

import { createContext, useContext, useEffect, useMemo, useState } from 'react'
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

  return <LiveContext.Provider value={live}>{children}</LiveContext.Provider>
}

/** Falls back to the server-rendered value until a refresh lands. */
export function useLive(slug: string, fallback: Live): Live {
  return useContext(LiveContext)[slug] ?? fallback
}
