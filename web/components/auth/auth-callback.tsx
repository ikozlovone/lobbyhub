'use client'

import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { useEffect, useState } from 'react'
import { RETURN_KEY } from './auth-dialog'
import { useAuth } from './auth-provider'

/**
 * Where a provider round trip lands.
 *
 * The token arrives in the URL fragment rather than the query string: a
 * fragment is never sent to a server, so it stays out of access logs, Referer
 * headers and anything sitting between the browser and us. It is read once and
 * the address bar is rewritten immediately, so it does not survive in history
 * either.
 */
export function AuthCallback() {
  const { adopt } = useAuth()
  const router = useRouter()
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const fragment = new URLSearchParams(window.location.hash.slice(1))
    const token = fragment.get('token')
    const failure = fragment.get('error')

    // Clear it before anything else can read it, whatever it turned out to be.
    history.replaceState(null, '', window.location.pathname)

    const back = sessionStorage.getItem(RETURN_KEY) ?? '/'
    sessionStorage.removeItem(RETURN_KEY)

    Promise.resolve(token ? adopt(token) : null).then((user) => {
      if (user) {
        router.replace(back)
      } else {
        setError(failure ?? 'That sign-in did not complete. Try again.')
      }
    })
  }, [adopt, router])

  return (
    <div className="flex min-h-64 items-center justify-center">
      {error ? (
        <div className="max-w-md rounded-lg border border-line bg-surface p-6 text-center">
          <h1 className="font-display text-lg font-bold">Sign-in did not finish</h1>
          <p className="mt-2 text-sm text-muted">{error}</p>
          <Link
            href="/"
            className="mt-4 inline-block cursor-pointer text-sm font-medium text-brand hover:underline"
          >
            Back to LobbyHub
          </Link>
        </div>
      ) : (
        <p className="text-sm text-subtle">Signing you in…</p>
      )}
    </div>
  )
}
