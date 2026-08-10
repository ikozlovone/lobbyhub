import type { Metadata } from 'next'
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
 * all of them at build time is the wrong trade. Without it `params` resolves at
 * request time, so this route has no static shell of its own and streams behind
 * the skeleton in ../../loading.tsx — which is the right shape for a page nobody
 * has necessarily asked for before.
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

export default function CountryPage({ params }: Props) {
  return (
    <GameListing
      route={async () => {
        const { game: gameSlug, country: countrySlug } = await params

        return {
          gameSlug,
          filters: { country: countrySlug },
          describe: (game) => {
            // Read at request time along with the listing, so the first server
            // in a country makes this page exist rather than 404 until the
            // window turns.
            const country = game.facets.countries.find(
              (candidate) => candidate.slug === countrySlug,
            )

            if (!country) return null

            return {
              heading: `${game.name} servers in ${country.name}`,
              crumb: country.name,
              facetLabel: country.name,
            }
          },
        }
      }}
    />
  )
}
