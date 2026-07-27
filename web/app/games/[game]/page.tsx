import type { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { cacheLife } from 'next/cache'
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
  // The shell — names, facets, descriptions — changes rarely. Player counts on
  // it are refreshed client-side by the live layer.
  cacheLife('hours')

  const { game: slug } = await params
  const game = await getGame(slug)

  if (!game) notFound()

  return (
    <GameListing
      gameSlug={slug}
      heading={`${game.name} servers`}
      intro={game.description ?? undefined}
    />
  )
}
