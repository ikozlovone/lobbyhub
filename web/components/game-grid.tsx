'use client'

import Link from 'next/link'
import { useDeferredValue, useMemo, useState } from 'react'
import type { Game } from '@/lib/api'
import { Icon } from './icons'

/**
 * Game search and grid.
 *
 * The whole catalog is 27 games and already on the page, so filtering happens
 * in memory: results appear on the keystroke instead of after a round trip.
 * Aliases are matched too — "mc" and "майнкрафт" both find Minecraft, which is
 * exactly what `games.aliases` was put in the schema for.
 */
export function GameGrid({ games }: { games: Game[] }) {
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
          className="w-full rounded-xl border border-line bg-surface py-3.5 pr-4 pl-11 outline-none transition-colors placeholder:text-subtle focus:border-brand"
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
              <GameCard game={game} />
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function GameCard({ game }: { game: Game }) {
  const empty = game.counters.servers === 0

  return (
    <Link
      href={`/games/${game.slug}`}
      className="group block cursor-pointer overflow-hidden rounded-xl border border-line bg-surface transition-colors hover:border-line-strong"
    >
      {/* Steam header art is 460x215; the ratio is reserved either way so a
          missing cover cannot shift the grid while images load. */}
      <div
        className="relative aspect-[460/215] overflow-hidden"
        style={{ backgroundColor: game.accent_color ?? 'var(--color-surface-2)' }}
      >
        {game.cover ? (
          <img
            src={game.cover}
            alt=""
            width={460}
            height={215}
            loading="lazy"
            className={`size-full object-cover transition-transform duration-300 group-hover:scale-[1.03] ${
              empty ? 'opacity-60' : ''
            }`}
          />
        ) : (
          <span className="font-display absolute inset-0 flex items-center justify-center px-2 text-center text-lg font-black text-white/90">
            {game.name}
          </span>
        )}
      </div>

      <div className="p-3">
        <h3 className="font-display truncate font-bold transition-colors group-hover:text-brand">
          {game.name}
        </h3>

        <dl className="mt-2 space-y-1 text-sm">
          <div className="flex justify-between gap-2">
            <dt className="text-subtle">Servers</dt>
            <dd className="tabular">{game.counters.servers.toLocaleString('en-US')}</dd>
          </div>
          <div className="flex justify-between gap-2">
            <dt className="text-subtle">Players on servers</dt>
            <dd className="tabular">{game.counters.players_online.toLocaleString('en-US')}</dd>
          </div>
        </dl>
      </div>
    </Link>
  )
}
