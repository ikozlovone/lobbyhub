'use client'

import Link from 'next/link'
import { useCallback, useEffect, useState } from 'react'
import { fetchFavorites, type FavoriteGroup } from '@/lib/favorites'
import { TOKEN_KEY, useAuth } from './auth/auth-provider'
import { SignInForm } from './auth/sign-in-form'
import { Icon } from './icons'
import { LiveProvider } from './live-provider'
import { ServerTable } from './server-table'

/**
 * Favourite servers: one block per game.
 *
 * Read in the browser on every visit, never prerendered and never cached. Every
 * other listing here is a shared page that can afford to be a minute old — this
 * one is the visitor's own list, and the moment it is stale it is telling them
 * something about their own data that is not true.
 *
 * Signed out, the page is the sign-in form rather than a wall with a button that
 * opens a modal: somebody who followed a link to their favourites has already
 * said what they want.
 */
export function FavoritesPage({ apiUrl }: { apiUrl: string }) {
  // Providers come off the context rather than through props: the layout
  // already fetched them once for the dialog, and threading deployment
  // configuration through a route would be a second source of the same answer.
  const { status, user, adopt, providers } = useAuth()
  const [groups, setGroups] = useState<FavoriteGroup[] | null>(null)
  const [failed, setFailed] = useState(false)

  /**
   * Read the list.
   *
   * Every piece of state lands inside the promise, never in the body: this is
   * called from an effect as well as from the Refresh button, and a setState
   * that runs synchronously inside an effect is a second render before the
   * first has been painted.
   *
   * Signing out does not clear what was read. The render below never reaches it
   * while anonymous, so clearing it would be work spent hiding something that
   * is not on screen.
   */
  const load = useCallback(
    (signal?: { cancelled: boolean }) =>
      Promise.resolve(localStorage.getItem(TOKEN_KEY))
        .then((token) => (token ? fetchFavorites(apiUrl, token) : []))
        .then((list) => {
          if (signal?.cancelled) return

          setGroups(list)
          setFailed(false)
        })
        // Distinct from an empty list on purpose — see lib/favorites.
        .catch(() => {
          if (!signal?.cancelled) setFailed(true)
        }),
    [apiUrl],
  )

  useEffect(() => {
    if (status !== 'signed-in') return

    const signal = { cancelled: false }

    void load(signal)

    return () => {
      signal.cancelled = true
    }
  }, [status, load])

  if (status === 'loading') {
    return <Skeleton />
  }

  if (status === 'anonymous') {
    return (
      <div className="mx-auto w-full max-w-md rounded-2xl border border-line bg-surface p-6 sm:p-8">
        <p className="font-display text-center text-lg font-black tracking-tight">
          LOBBY<span className="text-brand">HUB</span>
        </p>

        <SignInForm
          apiUrl={apiUrl}
          providers={providers}
          onSignedIn={(token) => void adopt(token)}
          title="Sign in to see your favorite servers"
        />
      </div>
    )
  }

  const total = groups?.reduce((count, group) => count + group.servers.length, 0) ?? 0

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="font-display text-2xl font-black tracking-tight sm:text-3xl">
            Favorite servers
          </h1>
          <p className="mt-1.5 text-sm text-subtle">
            {groups === null
              ? 'Reading your list…'
              : `${total} ${total === 1 ? 'server' : 'servers'} across ${groups.length} ${
                  groups.length === 1 ? 'game' : 'games'
                }, signed in as ${user?.name}`}
          </p>
        </div>

        <button
          type="button"
          onClick={() => void load()}
          className="flex cursor-pointer items-center gap-2 rounded-xl border border-line bg-surface px-3 py-2 text-sm text-muted transition-colors hover:text-fg"
        >
          <Icon.refresh />
          Refresh
        </button>
      </header>

      {failed && (
        <p className="rounded-2xl border border-accent/40 bg-accent/10 px-4 py-3 text-sm text-muted">
          Could not read your list just now. It is still there — try Refresh in a moment.
        </p>
      )}

      {groups === null && !failed && <Skeleton />}

      {groups !== null && groups.length === 0 && <Empty />}

      {groups?.map((group) => (
        <section key={group.game.slug} className="space-y-3">
          <div className="flex items-center gap-3">
            {group.game.cover && (
              <img
                src={group.game.cover}
                alt=""
                aria-hidden
                className="size-9 shrink-0 rounded-lg border border-line object-cover"
              />
            )}
            <h2 className="font-display text-lg font-bold tracking-tight">
              <Link href={`/games/${group.game.slug}`} className="transition-colors hover:text-brand">
                {group.game.name}
              </Link>
            </h2>
            <span
              className="tabular rounded-full px-2 py-0.5 text-xs text-muted"
              style={{
                backgroundColor: group.game.accent_color
                  ? `${group.game.accent_color}26`
                  : 'var(--color-surface-2)',
              }}
            >
              {group.servers.length}
            </span>
          </div>

          {/* The same table the listings use, so a favourite reads exactly like
              the row it was starred from — live counts included. */}
          <LiveProvider slugs={group.servers.map((server) => server.slug)}>
            <div className="overflow-hidden rounded-2xl border border-line bg-surface">
              <ServerTable
                servers={group.servers}
                steam={group.game.protocol === 'source'}
                onPickMap={() => {}}
              />
            </div>
          </LiveProvider>
        </section>
      ))}
    </div>
  )
}

function Empty() {
  return (
    <div className="rounded-2xl border border-line bg-surface px-4 py-16 text-center">
      <Icon.star className="mx-auto size-8 text-subtle" />
      <p className="mt-3 text-sm text-muted">Nothing starred yet.</p>
      <p className="mt-1 text-sm text-subtle">
        The star on any server — in a listing or on its own page — puts it here.{' '}
        <Link href="/" className="cursor-pointer text-brand hover:underline">
          Browse the catalog
        </Link>
        .
      </p>
    </div>
  )
}

function Skeleton() {
  return (
    <div aria-hidden className="space-y-4">
      <div className="h-9 w-64 animate-pulse rounded-lg bg-surface" />
      <div className="h-40 animate-pulse rounded-2xl bg-surface" />
      <div className="h-40 animate-pulse rounded-2xl bg-surface" />
    </div>
  )
}
