'use client'

import { useDeferredValue, useMemo, useState } from 'react'
import type { Game } from '@/lib/api'
import { GameCard } from './game-card'
import { Icon } from './icons'

/**
 * Game search and grid.
 *
 * The whole catalog is 27 games and already on the page, so filtering happens
 * in memory: results appear on the keystroke instead of after a round trip.
 * Aliases are matched too — "mc" and "майнкрафт" both find Minecraft, which is
 * exactly what `games.aliases` was put in the schema for.
 */
export function GameGrid({
  games,
  hrefSuffix = '',
}: {
  games: Game[]
  /**
   * Appended to /games/{slug}: the picker on /add-server points the same grid
   * at the submission forms. A string rather than a callback because this is a
   * client component and functions do not cross that boundary.
   */
  hrefSuffix?: string
}) {
  const [term, setTerm] = useState('')
  const deferred = useDeferredValue(term)

  const matches = useMemo(() => {
    const needle = deferred.trim().toLowerCase()

    if (needle === '') return games

    return games.filter((game) =>
      [game.name, game.short_name ?? '', ...(game.aliases ?? [])]
        .filter(Boolean)
        .some((candidate) => candidate.toLowerCase().includes(needle)),
    )
  }, [games, deferred])

  return (
    <div className="space-y-4">
      <div className="relative">
        <span aria-hidden className="absolute top-1/2 left-4 -translate-y-1/2 text-subtle">
          <Icon.search className="size-5" />
        </span>
        <label htmlFor="game-filter" className="sr-only">
          Search by game name
        </label>
        <input
          id="game-filter"
          value={term}
          onChange={(event) => setTerm(event.target.value)}
          placeholder="Search by game name"
          autoComplete="off"
          className="w-full rounded-xl border border-line bg-surface py-3.5 pr-4 pl-11 outline-none transition-colors placeholder:text-subtle"
        />
        {term && (
          <button
            type="button"
            onClick={() => setTerm('')}
            className="absolute top-1/2 right-3 -translate-y-1/2 cursor-pointer rounded px-2 py-1 text-xs text-subtle transition-colors hover:text-fg"
          >
            clear
          </button>
        )}
      </div>

      <p aria-live="polite" className="sr-only">
        {matches.length} games found
      </p>

      {matches.length === 0 ? (
        <p className="rounded-xl border border-line bg-surface px-4 py-10 text-center text-sm text-subtle">
          No game matches “{term}”.
        </p>
      ) : (
        <ul className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
          {matches.map((game) => (
            <li key={game.slug}>
              <GameCard game={game} href={`/games/${game.slug}${hrefSuffix}`} />
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

