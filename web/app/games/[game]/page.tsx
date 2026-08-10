import type { Metadata } from 'next'
import { GameListing } from '@/components/game-listing'
import { getGame, getGames } from '@/lib/data'
import { canonical, GAME_INDEX_THRESHOLD, notFoundMetadata, robotsFor } from '@/lib/seo'

type Props = { params: Promise<{ game: string }> }

/**
 * Every game, from the cached catalog.
 *
 * This is what keeps `params` resolved before the page renders rather than
 * suspending on it, and it is still worth doing now that the page body is read
 * at request time: prerendering produces the shell, not the listing.
 */
export async function generateStaticParams() {
  const games = await getGames()
  return games.map((game) => ({ game: game.slug }))
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { game: slug } = await params
  const game = await getGame(slug)

  if (!game) return notFoundMetadata()

  return {
    title: game.seo.title ?? `${game.name} servers`,
    description: game.seo.description ?? undefined,
    // A game we list but do not yet have servers for is the same thin page a
    // near-empty facet is. It stays browsable; it just should not be indexed.
    robots: robotsFor(game.counters.servers, GAME_INDEX_THRESHOLD),
    ...canonical(`/games/${game.slug}`),
  }
}

/**
 * A game's server list.
 *
 * Nothing on this route is cached, and that is deliberate: which servers are
 * listed, how many are online and what the chip counts say are the questions
 * the page exists to answer, and an answer from a minute ago is a wrong one.
 * GameListing does the reading behind Suspense boundaries so the shell still
 * prerenders — see the note there.
 */
export default function GamePage({ params }: Props) {
  return (
    <GameListing
      route={async () => {
        const { game: slug } = await params

        return {
          gameSlug: slug,
          describe: (game) => ({ heading: `${game.name} server list`, crumb: 'Servers' }),
        }
      }}
    />
  )
}
