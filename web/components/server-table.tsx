'use client'

import Link from 'next/link'
import type { Server } from '@/lib/api'
import { ConnectActions } from './connect-actions'
import { CountryFlag } from './country-flag'
import { FavoriteButton } from './favorite-button'
import { useLive } from './live-provider'

/**
 * The listing as a table.
 *
 * A real <table>, not a grid of divs: these are six comparable measurements of
 * the same kind of thing, and the column headers only mean anything to a screen
 * reader if the markup says they are headers.
 *
 * Map, version and votes drop out below their breakpoints rather than forcing a
 * horizontal scroll — a phone gets the three columns that decide whether to
 * tap, and the server page carries the rest.
 */
export function ServerTable({
  servers,
  steam,
  onPickMap,
}: {
  servers: Server[]
  steam: boolean
  onPickMap: (map: string) => void
}) {
  /*
   * Fixed layout, and every column but the name carries a width.
   *
   * Server names run to sixty characters of tags and emoji, and left to itself
   * the browser hands them whatever it takes to show one — starving the numbers
   * people came to compare. Fixed layout inverts that: the measurements get what
   * they need, the name gets the rest, and it is the name that truncates.
   */
  return (
    <table className="w-full table-fixed border-collapse text-sm">
      <thead>
        <tr className="border-b border-line text-left text-xs tracking-wide text-subtle uppercase">
          <th scope="col" className="w-11 px-3 py-2.5 font-medium">
            #
          </th>
          <th scope="col" className="px-3 py-2.5 font-medium">
            Name
          </th>
          {/* Wide enough for a flag, a full IPv4 with port, and both actions —
              a truncated address is one nobody can copy by reading it. */}
          <th scope="col" className="hidden w-72 px-3 py-2.5 font-medium sm:table-cell">
            Connection
          </th>
          <th scope="col" className="w-28 px-3 py-2.5 text-right font-medium">
            Online
          </th>
          <th scope="col" className="hidden w-40 px-3 py-2.5 font-medium lg:table-cell">
            Map
          </th>
          <th scope="col" className="hidden w-20 px-3 py-2.5 font-medium xl:table-cell">
            Version
          </th>
          <th scope="col" className="hidden w-20 px-3 py-2.5 text-right font-medium md:table-cell">
            Votes
          </th>
        </tr>
      </thead>

      <tbody>
        {/* Load more extends this array rather than replacing it, so the index
            is the position in the whole listing, not in the last batch. */}
        {servers.map((server, index) => (
          <Row
            key={server.slug}
            server={server}
            rank={index + 1}
            steam={steam}
            onPickMap={onPickMap}
          />
        ))}
      </tbody>
    </table>
  )
}

function Row({
  server,
  rank,
  steam,
  onPickMap,
}: {
  server: Server
  rank: number
  steam: boolean
  onPickMap: (map: string) => void
}) {
  const live = useLive(server.slug, server.live)
  const online = live.status === 'online'

  return (
    <tr
      className={`group border-b border-line transition-colors last:border-0 hover:bg-surface-2 ${
        server.promoted ? 'bg-accent/[0.06]' : ''
      }`}
    >
      <td className="tabular px-3 py-2.5 align-middle text-xs text-subtle">{rank}</td>

      <td className="px-3 py-2.5 align-middle">
        <div className="flex items-center gap-2">
          {/* Before the name rather than in a column of its own: a column would
              cost the name width on every row to serve the few that are
              starred, and this reads as part of the row's identity anyway. */}
          <FavoriteButton slug={server.slug} name={server.name} className="shrink-0" />
          <Link
            href={`/servers/${server.slug}`}
            // No prefetch. A listing holds two dozen of these and a visitor opens
            // one of them: prefetching the rest is the page fetching itself two
            // dozen times over, and since Next asks for each segment separately
            // that is nearer seventy requests — every one a render on our own
            // server, because the frontend reads the catalog from it.
            prefetch={false}
            className="block min-w-0 flex-1 truncate font-medium transition-colors group-hover:text-brand"
          >
            {server.name}
          </Link>
        </div>
        <span className="mt-0.5 flex items-center gap-2 text-xs text-subtle sm:hidden">
          <span className="tabular truncate">{server.address}</span>
        </span>
      </td>

      <td className="hidden px-3 py-2.5 align-middle sm:table-cell">
        <span className="flex items-center gap-2">
          {server.country && <CountryFlag country={server.country} city={server.city} />}
          <ConnectActions address={server.address} steam={steam} className="min-w-0" />
        </span>
      </td>

      {/* Capacity as a fraction, because 24 means nothing without the 100. */}
      <td className="tabular px-3 py-2.5 text-right align-middle whitespace-nowrap">
        {online ? (
          <>
            <span className={live.players > 0 ? 'text-fg' : 'text-subtle'}>
              {live.players.toLocaleString('en-US')}
            </span>
            <span className="text-subtle">/{live.max_players.toLocaleString('en-US')}</span>
          </>
        ) : (
          <span className="text-xs text-subtle">Offline</span>
        )}
      </td>

      <td className="hidden px-3 py-2.5 align-middle lg:table-cell">
        {server.map ? (
          /* Every map name in this column is also a filter — the fastest way to
             find more of what you are already looking at. */
          <button
            type="button"
            onClick={() => onPickMap(server.map as string)}
            title={`Show only ${server.map}`}
            className="block max-w-full cursor-pointer truncate text-xs text-muted transition-colors hover:text-brand"
          >
            {server.map}
          </button>
        ) : (
          <span className="text-xs text-subtle">—</span>
        )}
      </td>

      <td className="tabular hidden px-3 py-2.5 align-middle text-xs whitespace-nowrap text-muted xl:table-cell">
        {server.version ?? '—'}
      </td>

      <td className="tabular hidden px-3 py-2.5 text-right align-middle text-xs text-muted md:table-cell">
        {server.votes.toLocaleString('en-US')}
      </td>
    </tr>
  )
}
