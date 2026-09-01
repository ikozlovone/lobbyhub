'use client'

import Link from 'next/link'
import { useEffect, useState } from 'react'
import type { GameCounters as Counters, SteamCounters } from '@/lib/api'
import { Rolling } from './live-stats'

/**
 * The numbers in a game's hero, and the two of them that are easy to confuse.
 *
 * They arrive with the page and are true when they do — the hero is read at
 * request time. This keeps them true for as long as the tab stays open: these
 * are the whole reason someone opens a monitoring site, and a number that
 * stopped moving an hour ago is worse than one that arrived late. Same
 * treatment as the home page totals: re-read on an interval, roll to the new
 * value so a change is visible.
 */
const REFRESH_MS = 60_000

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'

export function GameCounters({
  game,
  initial,
  steam,
}: {
  game: string
  initial: Counters
  /** Steam's own count for the game. Absent until the collector has read it. */
  steam?: SteamCounters
}) {
  const [counters, setCounters] = useState(initial)
  const [inGame, setInGame] = useState(steam)

  useEffect(() => {
    const refresh = async () => {
      if (document.visibilityState !== 'visible') return

      try {
        const response = await fetch(`${API_URL}/games/${game}`, { cache: 'no-store' })

        if (response.ok) {
          const { data } = await response.json()

          setCounters(data.counters)
          setInGame(data.steam)
        }
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

      {/*
        The second player number, and the reason it is set apart rather than
        added to the row.

        "Players online" above is ours: the sum of what our monitor found on
        the servers it queried. This one is Steam's: everybody with the game
        open anywhere, including single-player and matchmaking. Listed as a
        fourth stat they would read as two attempts at the same figure, and the
        smaller one would look like a bug. Behind a divider, labelled by its
        source and linking to the page that explains the difference, they read
        as what they are — two measurements of two different things.

        Shown only once there is a reading: a game the collector has not
        reached yet has no number, which is not the same as nobody playing.
      */}
      {inGame?.synced_at && (
        <div className="flex items-baseline gap-1.5 border-l border-line pl-4">
          <dt className="sr-only">players in game on Steam</dt>
          <dd className="tabular text-lg font-medium sm:text-xl">
            <Rolling value={inGame.players_online} />
          </dd>
          <Link
            href={`/charts/${game}`}
            className="text-sm text-muted underline-offset-4 transition-colors hover:text-fg hover:underline"
          >
            in game on Steam
          </Link>
        </div>
      )}
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
