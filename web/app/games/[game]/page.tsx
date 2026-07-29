import type { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { cacheLife, cacheTag } from 'next/cache'
import { GameListing } from '@/components/game-listing'
import { getGame, getGames } from '@/lib/data'
import { canonical, GAME_INDEX_THRESHOLD, robotsFor } from '@/lib/seo'

type Props = { params: Promise<{ game: string }> }

export async function generateStaticParams() {
  const games = await getGames()
  return games.map((game) => ({ game: game.slug }))
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { game: slug } = await params
  const game = await getGame(slug)

  if (!game) return {}

  return {
    title: game.seo.title ?? `${game.name} servers`,
    description: game.seo.description ?? undefined,
    // A game we list but do not yet have servers for is the same thin page a
    // near-empty facet is. It stays browsable; it just should not be indexed.
    robots: robotsFor(game.counters.servers, GAME_INDEX_THRESHOLD),
    ...canonical(`/games/${game.slug}`),
  }
}

export default async function GamePage({ params }: Props) {
  'use cache'
  /*
   * Names, facets and descriptions change rarely, and the live layer refreshes
   * the player counts client-side — but *which servers are on the page* is part
   * of this markup, and a server added a minute ago has to be findable. The
   * rendered page is its own cache entry, so this window is the one that
   * decides, not the shorter one on getServers underneath it.
   */
  cacheLife('minutes')

  const { game: slug } = await params
  // Tagged on the page itself, not only inside getGame: this rendered markup is
  // its own cache entry, and it is the one a visitor is served.
  cacheTag('games', `game:${slug}`)
  const game = await getGame(slug)

  if (!game) notFound()

  return <GameListing gameSlug={slug} heading={`${game.name} server list`} />
}
