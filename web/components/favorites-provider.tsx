'use client'

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { addFavorite, fetchFavorites, removeFavorite } from '@/lib/favorites'
import { TOKEN_KEY, useAuth } from './auth/auth-provider'
import { useToast } from './toast'

/**
 * Which servers the signed-in visitor has starred.
 *
 * One request per page load, shared by every star on it: a listing draws
 * twenty-five of them, and twenty-five requests asking the same question would
 * be twenty-five ways to get a different answer.
 *
 * The set is loaded from the same endpoint the favourites page reads. It is
 * deliberately not persisted anywhere: a star is somebody's own data, and a
 * cached copy of it is a copy that can be wrong, in a way a page-load-old
 * player count never is.
 */

type FavoritesState = {
  /** Null until the list has been read — a star that starts hollow and fills in reads as a glitch. */
  slugs: Set<string> | null
  toggle: (slug: string) => void
}

const FavoritesContext = createContext<FavoritesState | null>(null)

export function useFavorites(): FavoritesState {
  return useContext(FavoritesContext) ?? { slugs: null, toggle: () => {} }
}

export function FavoritesProvider({
  apiUrl,
  children,
}: {
  apiUrl: string
  children: React.ReactNode
}) {
  const { status, signIn } = useAuth()
  const [slugs, setSlugs] = useState<Set<string> | null>(null)
  const toast = useToast()

  useEffect(() => {
    if (status !== 'signed-in') return

    const token = localStorage.getItem(TOKEN_KEY)

    if (!token) return

    let cancelled = false

    fetchFavorites(apiUrl, token)
      .then((groups) => {
        if (cancelled) return

        setSlugs(new Set(groups.flatMap((group) => group.servers.map((server) => server.slug))))
      })
      // Left null on failure, which keeps every star hollow rather than
      // claiming this visitor has starred nothing.
      .catch(() => {})

    return () => {
      cancelled = true
    }
  }, [apiUrl, status])

  const toggle = useCallback(
    (slug: string) => {
      // A star is a thing you do to something you are looking at, so being
      // signed out is not an error here — it is the first step.
      if (status !== 'signed-in') {
        signIn()

        return
      }

      const token = localStorage.getItem(TOKEN_KEY)

      if (!token) return

      const starred = slugs?.has(slug) ?? false

      // Moved before the request lands and put back if it fails: this is one
      // bit of state the visitor already knows the value of, and waiting for a
      // round trip to show it makes the button feel broken.
      const write = (on: boolean) =>
        setSlugs((current) => {
          const next = new Set(current ?? [])

          if (on) next.add(slug)
          else next.delete(slug)

          return next
        })

      write(!starred)

      const call = starred ? removeFavorite : addFavorite

      void call(apiUrl, token, slug).then((ok) => {
        if (ok) return

        write(starred)
        toast.error('Error', 'Could not save that. Try again in a moment.')
      })
    },
    [apiUrl, signIn, slugs, status, toast],
  )

  // Derived rather than cleared on sign-out: a signed-out visitor has no list
  // to speak of, and emptying the state in an effect would be a render spent
  // hiding something nobody is looking at.
  const value = useMemo<FavoritesState>(
    () => ({ slugs: status === 'signed-in' ? slugs : null, toggle }),
    [slugs, status, toggle],
  )

  return <FavoritesContext.Provider value={value}>{children}</FavoritesContext.Provider>
}
