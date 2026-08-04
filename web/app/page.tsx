import type { Metadata } from 'next'
import { Suspense } from 'react'
import { BenefitsSection } from '@/components/home/benefits-section'
import { HOME_COPY } from '@/components/home/copy'
import { HeroSection } from '@/components/home/hero-section'
import { HomeSeoContent } from '@/components/home/home-seo-content'
import { PopularGamesSection } from '@/components/home/popular-games-section'
import { ServerOwnerCta } from '@/components/home/server-owner-cta'
import { ServerSection } from '@/components/server-section'
import {
  getGamesWithCounters,
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
 * Every collection is read when the request arrives — these are the counts a
 * visitor judges the whole site by, and the live layer can only refresh rows
 * already on screen, never add the server that became busiest since. Each sits
 * behind its own Suspense boundary, so the hero and the copy are the static
 * shell and a slow section delays only itself.
 *
 * The reads are independent, and each swallows its own failure inside lib/data
 * — see catalogSection. A collection with nothing in it renders nothing, so an
 * API outage leaves the page as hero, benefits and copy rather than as an error.
 */
export default function HomePage() {
  return (
    <div className="space-y-10">
      <HeroSection />

      <Suspense fallback={<GamesSkeleton />}>
        <Games />
      </Suspense>

      {/* Popular and trending share a boundary because they share an answer:
          trending is filtered against what popular already showed, so neither
          can be rendered until both have arrived. */}
      <Suspense fallback={<SectionSkeleton count={2} />}>
        <PopularAndTrending />
      </Suspense>

      <Suspense fallback={<SectionSkeleton />}>
        <RecentlyAdded />
      </Suspense>

      <Suspense fallback={<SectionSkeleton />}>
        <RecentlyWiped />
      </Suspense>

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

async function Games() {
  // The one read here that is allowed to fail loudly enough to matter: the grid
  // is the page's navigation. An empty list renders an empty grid, not an error.
  return <PopularGamesSection games={await getGamesWithCounters().catch(() => [])} />
}

async function PopularAndTrending() {
  const [popular, trending] = await Promise.all([getPopularServers(8), getTrendingServers(8)])

  // Trending repeats the busiest servers almost exactly in a small catalog, and
  // two near-identical strips one above the other read as a bug, not a feature.
  const shown = new Set(popular.map((server) => server.slug))
  const trendingRows = trending.filter((server) => !shown.has(server.slug))

  return (
    <div className="space-y-10">
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
    </div>
  )
}

async function RecentlyAdded() {
  return (
    <ServerSection
      title={HOME_COPY.recent.title}
      description={HOME_COPY.recent.description}
      servers={await getRecentlyAddedServers(8)}
      viewAllHref="/search"
    />
  )
}

async function RecentlyWiped() {
  return (
    /* No "view all": there is no wipe-filtered listing to send anyone to. */
    <ServerSection
      title={HOME_COPY.wiped.title}
      description={HOME_COPY.wiped.description}
      servers={await getRecentlyWipedServers(8)}
    />
  )
}

function GamesSkeleton() {
  return (
    <div className="space-y-4" aria-hidden>
      <div className="h-7 w-56 animate-pulse rounded bg-surface" />
      <ul className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
        {Array.from({ length: 5 }, (_, index) => (
          <li key={index} className="h-44 animate-pulse rounded-xl bg-surface" />
        ))}
      </ul>
    </div>
  )
}

/**
 * One strip's worth of waiting.
 *
 * A section renders nothing when its collection is empty, so this can be
 * followed by nothing at all — which is why it is one row of cards rather than
 * a full grid. Reserving four rows for something that may not exist is a bigger
 * shift than reserving one.
 */
function SectionSkeleton({ count = 1 }: { count?: number }) {
  return (
    <div className="space-y-10" aria-hidden>
      {Array.from({ length: count }, (_, section) => (
        <div key={section} className="space-y-4">
          <div className="h-7 w-64 animate-pulse rounded bg-surface" />
          <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            {Array.from({ length: 4 }, (_, card) => (
              <li key={card} className="h-44 animate-pulse rounded-xl bg-surface" />
            ))}
          </ul>
        </div>
      ))}
    </div>
  )
}
