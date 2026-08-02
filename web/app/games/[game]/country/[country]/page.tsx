import type { Metadata } from 'next'
import { notFound } from 'next/navigation'
import { cacheLife, cacheTag } from 'next/cache'
import { GameListing } from '@/components/game-listing'
import { getGame } from '@/lib/data'
import { canonical, notFoundMetadata, robotsFor } from '@/lib/seo'

/** /games/minecraft/country/germany */
type Props = { params: Promise<{ game: string; country: string }> }

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
async function findCountry(gameSlug: string, countrySlug: string) {
  const game = await getGame(gameSlug)
  const country = game?.facets.countries.find((candidate) => candidate.slug === countrySlug)

  return game && country ? { game, country } : null
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { game: gameSlug, country: countrySlug } = await params
  const found = await findCountry(gameSlug, countrySlug)

  if (!found) return notFoundMetadata()

  const { game, country } = found

  return {
    title: `${game.name} servers in ${country.name}`,
    description: `${game.name} servers hosted in ${country.name}, with live player counts and uptime.`,
    robots: robotsFor(country.servers_count),
    ...canonical(`/games/${game.slug}/country/${country.slug}`),
  }
}

export default async function CountryPage({ params }: Props) {
  'use cache'
  // Minutes, like the game page: a newly added server has to appear here too.
  cacheLife('minutes')

  const { game: gameSlug, country: countrySlug } = await params
  cacheTag('games', `game:${gameSlug}`)
  const found = await findCountry(gameSlug, countrySlug)

  if (!found) notFound()

  return (
    <GameListing
      gameSlug={gameSlug}
      filters={{ country: countrySlug }}
      heading={`${found.game.name} servers in ${found.country.name}`}
      crumb={found.country.name}
      facetLabel={found.country.name}
    />
  )
}
