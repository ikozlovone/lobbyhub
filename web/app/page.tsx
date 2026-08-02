import type { Metadata } from 'next'
import { BenefitsSection } from '@/components/home/benefits-section'
import { HOME_COPY } from '@/components/home/copy'
import { HeroSection } from '@/components/home/hero-section'
import { HomeSeoContent } from '@/components/home/home-seo-content'
import { PopularGamesSection } from '@/components/home/popular-games-section'
import { ServerOwnerCta } from '@/components/home/server-owner-cta'
import { ServerSection } from '@/components/server-section'
import {
  getGames,
  getPopularServers,
  getRecentlyAddedServers,
  getRecentlyWipedServers,
  getTrendingServers,
} from '@/lib/data'
import { canonical } from '@/lib/seo'

const SITE_URL = process.env.NEXT_PUBLIC_SITE_URL ?? 'http://localhost:3000'

const TITLE = 'LobbyHub — Find the Best Game Servers'
const DESCRIPTION =
  'Discover multiplayer game servers for Rust, Minecraft, Counter-Strike 2, DayZ and more. Compare server activity, locations and features on LobbyHub.'

export const metadata: Metadata = {
  // Absolute: this is the one page whose title should not read "… | LobbyHub".
  title: { absolute: TITLE },
  description: DESCRIPTION,
  ...canonical('/'),
  openGraph: {
    type: 'website',
    siteName: 'LobbyHub',
    url: '/',
    title: TITLE,
    description: DESCRIPTION,
  },
  twitter: { card: 'summary_large_image', title: TITLE, description: DESCRIPTION },
}

/**
 * The home page.
 *
 * Server-rendered end to end: the only browser code involved is the live player
 * counts refreshing rows that are already in the HTML, and the favourite stars.
 * Search is a plain GET form, so the first screen works with JavaScript off.
 *
 * The five reads are independent, and each swallows its own failure inside
 * lib/data — see catalogSection. A collection with nothing in it renders
 * nothing, so an API outage leaves the page as hero, benefits and copy rather
 * than as an error.
 */
export default async function HomePage() {
  const [games, popular, trending, recent, wiped] = await Promise.all([
    getGames().catch(() => []),
    getPopularServers(8),
    getTrendingServers(8),
    getRecentlyAddedServers(8),
    getRecentlyWipedServers(8),
  ])

  // Trending repeats the busiest servers almost exactly in a small catalog, and
  // two near-identical strips one above the other read as a bug, not a feature.
  const shown = new Set(popular.map((server) => server.slug))
  const trendingRows = trending.filter((server) => !shown.has(server.slug))

  return (
    <div className="space-y-10">
      <HeroSection />

      <PopularGamesSection games={games} />

      <ServerSection
        title={HOME_COPY.popular.title}
        description={HOME_COPY.popular.description}
        servers={popular}
        viewAllHref="/search"
      />

      <ServerSection
        title={HOME_COPY.trending.title}
        description={HOME_COPY.trending.description}
        servers={trendingRows}
        viewAllHref="/search"
      />

      <ServerSection
        title={HOME_COPY.recent.title}
        description={HOME_COPY.recent.description}
        servers={recent}
        viewAllHref="/search"
      />

      {/* No "view all": there is no wipe-filtered listing to send anyone to. */}
      <ServerSection
        title={HOME_COPY.wiped.title}
        description={HOME_COPY.wiped.description}
        servers={wiped}
      />

      <BenefitsSection />

      <ServerOwnerCta />

      <HomeSeoContent />

      {/*
       * WebSite + SearchAction. The action is only honest because /search really
       * does answer ?q= — it is a page, not an aspiration.
       *
       * No Organization block: it wants a logo URL, a legal name and social
       * profiles, none of which this deployment has configured. No aggregate
       * ratings or server counts either — invented numbers in structured data
       * are the one thing that reliably earns a manual penalty.
       */}
      <script
        type="application/ld+json"
        // A literal built here, with nothing user-supplied in it.
        dangerouslySetInnerHTML={{
          __html: JSON.stringify({
            '@context': 'https://schema.org',
            '@type': 'WebSite',
            name: 'LobbyHub',
            url: `${SITE_URL}/`,
            description: HOME_COPY.hero.subtitle,
            potentialAction: {
              '@type': 'SearchAction',
              target: {
                '@type': 'EntryPoint',
                urlTemplate: `${SITE_URL}/search?q={search_term_string}`,
              },
              'query-input': 'required name=search_term_string',
            },
          }),
        }}
      />
    </div>
  )
}
