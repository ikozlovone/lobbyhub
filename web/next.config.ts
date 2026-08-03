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
    // How long the browser may reuse a prefetched segment before asking again.
    //
    // The dynamic default is zero — every hover over a link that has already
    // been prefetched fetches it again, and a listing full of links turns that
    // into a stream of repeats. A minute is well inside the window the pages
    // themselves are cached for on the server, so nobody sees anything older
    // than they would have anyway.
    staleTimes: {
      dynamic: 60,
      static: 300,
    },
  },
}

export default nextConfig
