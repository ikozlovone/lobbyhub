'use client'

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { fetchMe, signOut, type AuthProviderInfo, type AuthUser } from '@/lib/auth'
import { useToast } from '../toast'
import { AuthDialog } from './auth-dialog'

/**
 * Who is signed in, for the whole app.
 *
 * The session is a Sanctum token in localStorage: the frontend and the API sit
 * on different origins, so a cookie would be third-party and dropped by every
 * browser that matters. The trade is explicit — a token readable by scripts is
 * a token an XSS bug can steal, which is why nothing on this site injects HTML
 * it did not author.
 *
 * The dialog lives here rather than in the header so that anything at all —
 * a vote button, a claim link — can ask for a signed-in visitor by calling
 * `signIn()`, without every one of those places owning a modal.
 */

export const TOKEN_KEY = 'lobbyhub.token'

type AuthState = {
  user: AuthUser | null
  /** `loading` until the stored token has been checked, so nothing flashes "Log in" at a signed-in visitor. */
  status: 'loading' | 'anonymous' | 'signed-in'
  providers: AuthProviderInfo[]
  signIn: () => void
  signOut: () => void
  /** Called by the OAuth callback page once it has a token from the fragment. */
  adopt: (token: string) => Promise<AuthUser | null>
}

const AuthContext = createContext<AuthState | null>(null)

export function useAuth(): AuthState {
  const context = useContext(AuthContext)

  if (context === null) {
    throw new Error('useAuth must be used inside <AuthProvider>')
  }

  return context
}

export function AuthProvider({
  apiUrl,
  providers,
  children,
}: {
  apiUrl: string
  providers: AuthProviderInfo[]
  children: React.ReactNode
}) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [status, setStatus] = useState<AuthState['status']>('loading')
  const [open, setOpen] = useState(false)
  // Bumped on every open so the dialog remounts: a half-typed address from an
  // hour ago, or a code that has since expired, is not what anyone wants back.
  const [attempt, setAttempt] = useState(0)
  const toast = useToast()

  // Restore the session before anything that depends on it is drawn. Both
  // paths resolve through a promise so the state lands after the effect rather
  // than during it — a synchronous setState here re-renders the whole tree.
  useEffect(() => {
    const token = localStorage.getItem(TOKEN_KEY)
    let cancelled = false

    Promise.resolve(token ? fetchMe(apiUrl, token) : null).then((found) => {
      if (cancelled) return

      // A token the API no longer honours is worse than none: it would keep
      // the header claiming a session that every request denies.
      if (token && !found) localStorage.removeItem(TOKEN_KEY)

      setUser(found)
      setStatus(found ? 'signed-in' : 'anonymous')
    })

    return () => {
      cancelled = true
    }
  }, [apiUrl])

  const adopt = useCallback(
    async (token: string) => {
      localStorage.setItem(TOKEN_KEY, token)

      const found = await fetchMe(apiUrl, token)

      if (!found) localStorage.removeItem(TOKEN_KEY)

      setUser(found)
      setStatus(found ? 'signed-in' : 'anonymous')

      // Announced here rather than in the dialog: a provider round trip lands
      // on /auth/callback and never touches it, and both routes are a sign-in.
      if (found) toast.success('Signed in', `Welcome, ${found.name}.`)

      return found
    },
    [apiUrl, toast],
  )

  const leave = useCallback(() => {
    const token = localStorage.getItem(TOKEN_KEY)

    localStorage.removeItem(TOKEN_KEY)
    setUser(null)
    setStatus('anonymous')

    if (token) void signOut(apiUrl, token)
  }, [apiUrl])

  const value = useMemo<AuthState>(
    () => ({
      user,
      status,
      providers,
      signIn: () => {
        setAttempt((count) => count + 1)
        setOpen(true)
      },
      signOut: leave,
      adopt,
    }),
    [user, status, providers, leave, adopt],
  )

  return (
    <AuthContext.Provider value={value}>
      {children}
      <AuthDialog
        key={attempt}
        apiUrl={apiUrl}
        providers={providers}
        open={open}
        onClose={() => setOpen(false)}
        onSignedIn={(token) => {
          void adopt(token)
          setOpen(false)
        }}
      />
    </AuthContext.Provider>
  )
}
