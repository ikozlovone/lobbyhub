'use client'

import { useEffect, useRef, useState } from 'react'
import type { Game } from '@/lib/api'

/**
 * Catalog totals.
 *
 * The competitor labels these "Live" but renders them once with the page. Ours
 * actually refresh, and the numbers roll to their new value so a change is
 * visible rather than something you have to catch.
 */
const REFRESH_MS = 60_000

export function LiveStats({ games: initial }: { games: Game[] }) {
  const [games, setGames] = useState(initial)

  useEffect(() => {
    const refresh = async () => {
      if (document.visibilityState !== 'visible') return

      try {
        const response = await fetch(
          `${process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'}/games`,
          { cache: 'no-store' },
        )
        if (response.ok) setGames((await response.json()).data)
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
  }, [])

  const totals = games.reduce(
    (sum, game) => ({
      servers: sum.servers + game.counters.servers,
      online: sum.online + game.counters.servers_online,
      players: sum.players + game.counters.players_online,
    }),
    { servers: 0, online: 0, players: 0 },
  )

  return (
    <div className="grid gap-3 sm:grid-cols-3">
      <Tile label="Servers" value={totals.servers} />
      <Tile label="Servers online" value={totals.online} />
      <Tile label="Players online" value={totals.players} />
    </div>
  )
}

function Tile({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-xl border border-line bg-surface px-5 py-4">
      <div className="flex items-center justify-between gap-2">
        <p className="truncate text-sm whitespace-nowrap text-subtle">{label}</p>
        <span className="flex shrink-0 items-center gap-1.5 text-[11px] text-online">
          <span aria-hidden className="relative flex size-2">
            <span className="absolute inline-flex size-full animate-ping rounded-full bg-online opacity-60" />
            <span className="relative inline-flex size-2 rounded-full bg-online" />
          </span>
          Live
        </span>
      </div>
      <p className="tabular mt-1 text-2xl font-medium sm:text-3xl">
        <Rolling value={value} />
      </p>
    </div>
  )
}

/**
 * Counts from the previous value to the new one. Purely cosmetic, so it is
 * skipped entirely for anyone who asked for less motion, and on first paint —
 * animating up from zero on load would just be noise.
 *
 * Exported for the game hero, which shows the same kind of number and should
 * behave the same way when it moves.
 */
export function Rolling({ value }: { value: number }) {
  const [shown, setShown] = useState(value)
  const previous = useRef(value)

  useEffect(() => {
    const from = previous.current
    previous.current = value

    if (from === value) return

    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    const distance = Math.abs(value - from)

    if (reduced || distance > from * 0.5) {
      setShown(value)

      return
    }

    const started = performance.now()
    const duration = 600
    let frame = 0

    const step = (now: number) => {
      const progress = Math.min((now - started) / duration, 1)
      // Ease-out: fast at first, settling into the final number.
      setShown(Math.round(from + (value - from) * (1 - (1 - progress) ** 3)))

      if (progress < 1) frame = requestAnimationFrame(step)
    }

    frame = requestAnimationFrame(step)

    return () => cancelAnimationFrame(frame)
  }, [value])

  return <>{shown.toLocaleString('en-US')}</>
}
