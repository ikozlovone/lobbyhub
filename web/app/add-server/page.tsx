import type { Metadata } from 'next'
import Link from 'next/link'
import { cacheLife } from 'next/cache'
import { CATALOG_CACHE } from '@/lib/cache'
import { GameGrid } from '@/components/game-grid'
import { getGames } from '@/lib/data'
import { canonical } from '@/lib/seo'

export const metadata: Metadata = {
  title: 'Add your server',
  description:
    'Add a Minecraft, Rust, FiveM or Source server to LobbyHub. One address, verified by our own query — no forms about player counts.',
  ...canonical('/add-server'),
}

/**
 * Step one of adding a server: pick the game.
 *
 * The same grid as the home page, pointed at the submission forms. A separate
 * "choose a game" step exists because the form itself is game-specific — the
 * default port, the protocol and the requirements all come from the game.
 */
export default async function AddServerPage() {
  'use cache'
  cacheLife(CATALOG_CACHE)

  const games = await getGames()

  // Alphabetical, not by size: an owner arrives knowing which game they run,
  // and hunting for it in a popularity ranking is the slower read.
  const ordered = [...games].sort((a, b) => a.name.localeCompare(b.name))

  return (
    <div className="space-y-6">
      <header className="space-y-2">
        <nav aria-label="Breadcrumb" className="text-xs text-subtle">
          <Link href="/" className="hover:text-fg">
            LobbyHub
          </Link>
          <span className="mx-1.5">/</span>
          <span className="text-muted">Add server</span>
        </nav>
        <h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl">
          Choose a game to add your server
        </h1>
        <p className="max-w-3xl text-sm text-muted">
          Adding a server takes one field: the address players connect to. We query it ourselves,
          and everything the listing shows — name, players, map, version, uptime — comes from that
          check rather than from what you type.
        </p>
      </header>

      <GameGrid games={ordered} hrefSuffix="/add-server" />
    </div>
  )
}
