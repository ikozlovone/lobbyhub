import type { NextConfig } from 'next'

const nextConfig: NextConfig = {
  // Cache Components replace the old `export const revalidate` model: pages opt
  // into caching with `use cache` + cacheLife, and whatever is left uncached
  // stays dynamic. That is exactly the split this catalog needs — a cached shell
  // with live player counts on top.
  cacheComponents: true,
}

export default nextConfig
