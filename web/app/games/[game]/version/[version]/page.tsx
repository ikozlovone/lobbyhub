import type { Metadata } from 'next'
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
 * all of them at build time is the wrong trade. Without it `params` resolves at
 * request time, so this route has no static shell of its own and streams behind
 * the skeleton in ../../loading.tsx — which is the right shape for a page nobody
 * has necessarily asked for before.
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
  const { game: gameSlug, version: versionSlug } = await params

  return (
    <GameListing
      gameSlug={gameSlug}
      filters={{ version: versionSlug }}
      describe={(game) => {
        // Read at request time along with the listing: a version appears in the
        // facets the moment a server reports it, and so should its page.
        const version = game.facets.versions.find((candidate) => candidate.slug === versionSlug)

        if (!version) return null

        return {
          heading: `${game.name} ${version.name} servers`,
          crumb: version.name,
          facetLabel: version.name,
        }
      }}
    />
  )
}
