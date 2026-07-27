import type { MetadataRoute } from 'next'

const SITE = process.env.NEXT_PUBLIC_SITE_URL ?? 'http://localhost:3000'

export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: '*',
      allow: '/',
      // Sorted and paginated variants are the same listings in another order.
      disallow: ['/*?sort=', '/*?page='],
    },
    sitemap: `${SITE}/sitemap.xml`,
  }
}
