'use client'

import Link from 'next/link'
import { useEffect, useRef, useState } from 'react'
import { Icon } from './icons'

type Results = {
  games: { slug: string; name: string; servers_count: number }[]
  servers: { slug: string; name: string; game: string; players: number; status: string }[]
}

/**
 * Global search. Debounced, because it fires on every keystroke and the API is
 * rate limited; closes on Escape and on click-away.
 */
export function SearchBox({ apiUrl }: { apiUrl: string }) {
  const [term, setTerm] = useState('')
  const [results, setResults] = useState<Results | null>(null)
  const [open, setOpen] = useState(false)
  const container = useRef<HTMLDivElement>(null)

  // Too short to search is derived, not stored: clearing the results in an
  // effect would set state on the way *in* to a render that already knows the
  // answer. `results` keeps the last response; whether it is shown is decided
  // below, so a shrinking term hides them instantly instead of a render later.
  const short = term.trim().length < 2

  useEffect(() => {
    if (short) return

    const timer = setTimeout(async () => {
      try {
        const response = await fetch(`${apiUrl}/search?q=${encodeURIComponent(term.trim())}`, {
          cache: 'no-store',
        })
        if (response.ok) {
          setResults((await response.json()).data)
          setOpen(true)
        }
      } catch {
        // A failed search should leave the box quiet, not shout.
      }
    }, 250)

    return () => clearTimeout(timer)
  }, [term, apiUrl, short])

  useEffect(() => {
    const onClick = (event: MouseEvent) => {
      if (!container.current?.contains(event.target as Node)) setOpen(false)
    }
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }

    document.addEventListener('mousedown', onClick)
    document.addEventListener('keydown', onKey)

    return () => {
      document.removeEventListener('mousedown', onClick)
      document.removeEventListener('keydown', onKey)
    }
  }, [])

  const shown = short ? null : results
  const hasResults = (shown?.games.length ?? 0) + (shown?.servers.length ?? 0) > 0

  return (
    <div ref={container} className="relative w-full max-w-xl">
      <label className="sr-only" htmlFor="search">
        Search games and servers
      </label>
      <span aria-hidden className="absolute top-1/2 left-3 -translate-y-1/2 text-subtle">
        <Icon.search />
      </span>
      <input
        id="search"
        value={term}
        onChange={(event) => setTerm(event.target.value)}
        onFocus={() => shown && setOpen(true)}
        placeholder="Search games and servers"
        autoComplete="off"
        className="w-full rounded-lg border border-line bg-surface py-2 pr-3 pl-9 text-sm outline-none transition-colors placeholder:text-subtle"
      />

      {open && shown && (
        <div className="absolute top-full z-30 mt-1.5 w-full overflow-hidden rounded-lg border border-line bg-surface shadow-xl">
          {!hasResults && <p className="px-3 py-4 text-sm text-subtle">Nothing found.</p>}

          {shown.games.length > 0 && (
            <ul className="border-b border-line py-1">
              {shown.games.map((game) => (
                <li key={game.slug}>
                  <Link
                    href={`/games/${game.slug}`}
                    onClick={() => setOpen(false)}
                    className="flex cursor-pointer items-center justify-between gap-3 px-3 py-2 text-sm transition-colors hover:bg-surface-2"
                  >
                    <span className="truncate">{game.name}</span>
                    <span className="tabular shrink-0 text-xs text-subtle">
                      {game.servers_count} servers
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          )}

          {shown.servers.length > 0 && (
            <ul className="py-1">
              {shown.servers.map((server) => (
                <li key={server.slug}>
                  <Link
                    href={`/servers/${server.slug}`}
                    onClick={() => setOpen(false)}
                    className="flex cursor-pointer items-center justify-between gap-3 px-3 py-2 text-sm transition-colors hover:bg-surface-2"
                  >
                    <span className="min-w-0">
                      <span className="block truncate">{server.name}</span>
                      <span className="text-xs text-subtle">{server.game}</span>
                    </span>
                    <span
                      className={`tabular shrink-0 text-xs ${
                        server.status === 'online' ? 'text-online' : 'text-subtle'
                      }`}
                    >
                      {server.status === 'online' ? `${server.players} online` : 'offline'}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  )
}
