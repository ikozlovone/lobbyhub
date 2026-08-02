/**
 * Every user-facing string on the home page, in one object.
 *
 * There is no i18n framework in the project yet, and adding one was not part of
 * this work. Grouping the copy here is the cheap half of being ready for it:
 * when a dictionary arrives, this file becomes the English entry in it and the
 * components change from `HOME_COPY.x` to `t('home.x')` — rather than somebody
 * having to hunt sentences out of eight components first.
 *
 * Claims made here have to be true of the site as it actually is. The benefits
 * below each name a feature that exists: live data from our own queries, the
 * filters on a game listing, the history chart on a server page, and the
 * catalog itself.
 */
export const HOME_COPY = {
  hero: {
    subtitle:
      'Browse multiplayer servers for Rust, Minecraft, Counter-Strike 2, DayZ and more. Compare player counts, locations, ratings and server features.',
    searchLabel: 'Search servers by name, game or address',
    searchPlaceholder: 'Search servers by name, game or address',
  },

  games: {
    title: 'Browse Servers by Game',
    description:
      'Choose a game to discover active multiplayer servers, communities and player statistics.',
    viewAll: 'View all games',
  },

  popular: {
    title: 'Popular Game Servers',
    description: 'Explore active servers with growing communities and high player activity.',
  },

  trending: {
    title: 'Trending Servers',
    description: 'Servers climbing our ranking on recent votes and measured player activity.',
  },

  recent: {
    title: 'Recently Added Servers',
    description: 'The newest additions to the catalog, verified by our own query.',
  },

  wiped: {
    title: 'Recently Wiped Servers',
    description: 'Fresh starts from the last two weeks, for games where a wipe means something.',
  },

  benefits: {
    title: 'Find the Right Server Faster',
    items: [
      {
        title: 'Live Server Data',
        detail:
          'Check player counts, server status, location and other available server information.',
      },
      {
        title: 'Powerful Filters',
        detail: 'Filter servers by game, region, player count, game mode and server features.',
      },
      {
        title: 'Server History',
        detail: 'Track server activity and population changes over the past days and weeks.',
      },
      {
        title: 'Community Discovery',
        detail:
          'Discover new multiplayer communities and find servers that match your playstyle.',
      },
    ],
  },

  owners: {
    title: 'Grow Your Game Server Community',
    description:
      'Add your server to LobbyHub, keep its information up to date and help new players discover your community.',
    action: 'Add your server',
  },

  seo: {
    title: 'Discover Multiplayer Game Servers',
    paragraphs: [
      'LobbyHub helps players discover multiplayer game servers for popular titles such as Rust, Minecraft, Counter-Strike 2, DayZ and ARK. Browse available servers, compare player activity, locations and server features, and choose a community that matches your preferred playstyle.',
      'Whether you are looking for a competitive PvP server, a relaxed PvE community, a modded experience or a classic vanilla server, LobbyHub makes it easier to explore available options. Server owners can also add their communities and provide players with accurate server information.',
      'Every number on this site comes from our own checks. We query each listed server directly, every few minutes, and publish what it answers — player counts, availability, map and version. Nothing here is typed in by an owner hoping to look busier than they are, which is what makes comparing two servers worth doing at all. Uptime is the share of those checks a server answered, so a listing that claims to be online and is not will show it within minutes rather than never.',
    ],
  },
} as const
