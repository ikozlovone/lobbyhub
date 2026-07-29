'use client'

import { useEffect, useState } from 'react'
import type { GameCounters as Counters } from '@/lib/api'
import { Rolling } from './live-stats'

/**
 * The three numbers in a game's hero.
 *
 * The page shell around them is cached for hours, so the copy baked into it
 * would be hours old — these are the whole reason someone opens a monitoring
 * site, and stale is worse than late. Same treatment as the home page totals:
 * re-read on an interval, roll to the new value so a change is visible.
 */
const REFRESH_MS = 60_000

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'

export function GameCounters({ game, initial }: { game: string; initial: Counters }) {
  const [counters, setCounters] = useState(initial)

  useEffect(() => {
    const refresh = async () => {
      if (document.visibilityState !== 'visible') return

      try {
        const response = await fetch(`${API_URL}/games/${game}`, { cache: 'no-store' })
        if (response.ok) setCounters((await response.json()).data.counters)
      } catch {
        // Keep the last good numbers rather than blanking the hero.
      }
    }

    const timer = setInterval(refresh, REFRESH_MS)
    document.addEventListener('visibilitychange', refresh)

    return () => {
      clearInterval(timer)
      document.removeEventListener('visibilitychange', refresh)
    }
  }, [game])

  return (
    <dl className="flex flex-wrap items-baseline gap-x-6 gap-y-1">
      <Stat label="servers" value={counters.servers} />
      <Stat label="servers online" value={counters.servers_online} />
      <Stat label="players online" value={counters.players_online} />
    </dl>
  )
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="flex items-baseline gap-1.5">
      <dt className="sr-only">{label}</dt>
      <dd className="tabular text-lg font-medium sm:text-xl">
        <Rolling value={value} />
      </dd>
      <span aria-hidden className="text-sm text-muted">
        {label}
      </span>
    </div>
  )
}
