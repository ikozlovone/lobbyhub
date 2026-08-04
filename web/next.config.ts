import type { NextConfig } from 'next'

const nextConfig: NextConfig = {
  turbopack: {
    // Pinned, because Next infers this by looking upwards for a lockfile and the
    // Laravel app one directory up has its own. Inferring the repository root
    // instead of this one moves where module resolution and file watching are
    // scoped — and on a server where someone has run npm at the top level, the
    // build starts guessing differently than it did locally.
    root: import.meta.dirname,
  },

  // Cache Components replace the old `export const revalidate` model: pages opt
  // into caching with `use cache` + cacheLife, and whatever is left uncached
  // stays dynamic. That is exactly the split this catalog needs — a cached shell
  // with live player counts on top.
  cacheComponents: true,


  experimental: {
    // How long the router may reuse a segment it already has before asking the
    // server again.
    //
    // Both zero, which is the whole point. This used to be 60/300, on the
    // reasoning that the pages were cached on the server for longer anyway so
    // reuse cost nothing. That stopped being true when the listings became
    // request-time reads: leave a game page and come back inside the window and
    // the router would answer from memory without a request, so the visitor
    // would be looking at the rows from their previous visit — the one thing
    // this whole split exists to prevent.
    //
    // Not a real extra request either. The dynamic half of these routes has to
    // be fetched on arrival regardless; letting the static shell ride along on
    // that same response is free.
    //
    // `dynamic` is the one that governs the catalog: these routes all have an
    // uncached half, so zero means the router asks again every time. `static`
    // covers the fully prerendered pages — terms, privacy — and 30 is simply
    // the lowest the config schema accepts; there is nothing on those to go
    // stale.
    //
    // Back and forward are unaffected — Next keeps its own cache for those to
    // hold scroll position, and that is the behaviour people expect there.
    staleTimes: {
      dynamic: 0,
      static: 30,
    },
  },
}

export default nextConfig
