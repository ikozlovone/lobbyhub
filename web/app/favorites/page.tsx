import type { Metadata } from 'next'
import { FavoritesPage } from '@/components/favorites-page'

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'

export const metadata: Metadata = {
  title: 'Favorite servers',
  description: 'The servers you starred, grouped by game.',
  // One visitor's own list. There is nothing here for a crawler to index, and
  // an indexed URL that answers "sign in" to everyone is worse than none.
  robots: { index: false, follow: false },
}

/**
 * Deliberately thin, and deliberately not cached.
 *
 * The whole page is drawn in the browser from the account's own token — see
 * FavoritesPage. Nothing about it can live in a shared shell: the token is in
 * localStorage, and a prerender would be a page of somebody else's list or of
 * nobody's.
 */
export default function Page() {
  return <FavoritesPage apiUrl={API_URL} />
}
