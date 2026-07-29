import type { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { cacheLife, cacheTag } from 'next/cache'
import { GameListing } from '@/components/game-listing'
import { getGame } from '@/lib/data'
import { canonical, robotsFor } from '@/lib/seo'

/**
 * /games/minecraft/survival
 *
 * Static segments win over dynamic ones in the App Router, so `version` and
 * `country` under the same parent are matched by their own folders and never
 * fall into this catch-all.
 */
type Props = { params: Promise<{ game: string; mode: string }> }

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
async function findMode(gameSlug: string, modeSlug: string) {
  const game = await getGame(gameSlug)
  const mode = game?.facets.modes.find((candidate) => candidate.slug === modeSlug)

  return game && mode ? { game, mode } : null
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { game: gameSlug, mode: modeSlug } = await params
  const found = await findMode(gameSlug, modeSlug)

  if (!found) return {}

  const { game, mode } = found

  return {
    title: `${mode.name} ${game.name} servers`,
    description: `${mode.name} ${game.name} servers with live player counts and uptime history.`,
    robots: robotsFor(mode.servers_count),
    ...canonical(`/games/${game.slug}/${mode.slug}`),
  }
}

export default async function ModePage({ params }: Props) {
  'use cache'
  // Minutes, like the game page: a newly added server has to appear here too.
  cacheLife('minutes')

  const { game: gameSlug, mode: modeSlug } = await params
  cacheTag('games', `game:${gameSlug}`)
  const found = await findMode(gameSlug, modeSlug)

  if (!found) notFound()

  return (
    <GameListing
      gameSlug={gameSlug}
      filters={{ mode: modeSlug }}
      heading={`${found.mode.name} ${found.game.name} servers`}
      crumb={found.mode.name}
      facetLabel={found.mode.name}
    />
  )
}
