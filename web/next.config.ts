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
}

export default nextConfig
