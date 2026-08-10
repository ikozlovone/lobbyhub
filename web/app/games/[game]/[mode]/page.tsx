import type { Metadata } from 'next'
import { GameListing } from '@/components/game-listing'
import { getGame } from '@/lib/data'
import { canonical, notFoundMetadata, robotsFor } from '@/lib/seo'

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
 * all of them at build time is the wrong trade. Without it `params` resolves at
 * request time, so this route has no static shell of its own and streams behind
 * the skeleton in ../loading.tsx — which is the right shape for a page nobody
 * has necessarily asked for before.
 */
async function findMode(gameSlug: string, modeSlug: string) {
  const game = await getGame(gameSlug)
  const mode = game?.facets.modes.find((candidate) => candidate.slug === modeSlug)

  return game && mode ? { game, mode } : null
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { game: gameSlug, mode: modeSlug } = await params
  const found = await findMode(gameSlug, modeSlug)

  if (!found) return notFoundMetadata()

  const { game, mode } = found

  return {
    title: `${mode.name} ${game.name} servers`,
    description: `${mode.name} ${game.name} servers with live player counts and uptime history.`,
    robots: robotsFor(mode.servers_count),
    ...canonical(`/games/${game.slug}/${mode.slug}`),
  }
}

export default function ModePage({ params }: Props) {
  return (
    <GameListing
      route={async () => {
        const { game: gameSlug, mode: modeSlug } = await params

        return {
          gameSlug,
          filters: { mode: modeSlug },
          describe: (game) => {
            // Read at request time along with the listing, so a mode that was
            // categorised a minute ago is a page rather than a 404.
            const mode = game.facets.modes.find((candidate) => candidate.slug === modeSlug)

            if (!mode) return null

            return {
              heading: `${mode.name} ${game.name} servers`,
              crumb: mode.name,
              facetLabel: mode.name,
            }
          },
        }
      }}
    />
  )
}
