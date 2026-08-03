import type { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { cacheLife, cacheTag } from 'next/cache'
import { CATALOG_CACHE } from '@/lib/cache'
import { GameListing } from '@/components/game-listing'
import { getGame } from '@/lib/data'
import { canonical, notFoundMetadata, robotsFor } from '@/lib/seo'

/** /games/minecraft/version/1-21 */
type Props = { params: Promise<{ game: string; version: string }> }

/*
 * No generateStaticParams here on purpose.
 *
 * Cache Components require it to return at least one param, and a facet list is
 * legitimately empty until servers are categorised. More importantly, the long
 * tail of facet pages is meant to number in the tens of thousands — prerendering
 * all of them at build time is the wrong trade. Without it each page renders on
 * first request and is then cached by the `use cache` scope below, which is the
 * behaviour we actually want.
 */
async function findVersion(gameSlug: string, versionSlug: string) {
  const game = await getGame(gameSlug)
  const version = game?.facets.versions.find((candidate) => candidate.slug === versionSlug)

  return game && version ? { game, version } : null
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { game: gameSlug, version: versionSlug } = await params
  const found = await findVersion(gameSlug, versionSlug)

  if (!found) return notFoundMetadata()

  const { game, version } = found

  return {
    title: `${game.name} ${version.name} servers`,
    description: `${game.name} servers running version ${version.name}, with live player counts.`,
    robots: robotsFor(version.servers_count),
    ...canonical(`/games/${game.slug}/version/${version.slug}`),
  }
}

export default async function VersionPage({ params }: Props) {
  'use cache'
  // Minutes, like the game page: a newly added server has to appear here too.
  cacheLife(CATALOG_CACHE)

  const { game: gameSlug, version: versionSlug } = await params
  cacheTag('games', `game:${gameSlug}`)
  const found = await findVersion(gameSlug, versionSlug)

  if (!found) notFound()

  return (
    <GameListing
      gameSlug={gameSlug}
      filters={{ version: versionSlug }}
      heading={`${found.game.name} ${found.version.name} servers`}
      crumb={found.version.name}
      facetLabel={found.version.name}
    />
  )
}
