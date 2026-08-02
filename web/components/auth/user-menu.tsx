'use client'

import Link from 'next/link'
import { useEffect, useRef, useState } from 'react'
import { Icon } from '../icons'
import { useAuth } from './auth-provider'

/**
 * The right-hand end of the header: one button that is either the way in or the
 * account it let you into.
 */
export function UserMenu() {
  const { user, status, signIn, signOut } = useAuth()
  const [open, setOpen] = useState(false)
  const container = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const clickAway = (event: MouseEvent) => {
      if (!container.current?.contains(event.target as Node)) setOpen(false)
    }
    const escape = (event: KeyboardEvent) => event.key === 'Escape' && setOpen(false)

    document.addEventListener('mousedown', clickAway)
    document.addEventListener('keydown', escape)

    return () => {
      document.removeEventListener('mousedown', clickAway)
      document.removeEventListener('keydown', escape)
    }
  }, [])

  // Nothing is drawn until the stored token has been checked. A "Log in" button
  // that turns into an avatar half a second later reads as a glitch.
  if (status === 'loading') {
    return <div aria-hidden className="h-8 w-20 shrink-0 animate-pulse rounded-lg bg-surface" />
  }

  if (!user) {
    return (
      <button
        type="button"
        onClick={signIn}
        className="shrink-0 cursor-pointer rounded-lg border border-line px-3 py-1.5 text-sm font-medium transition-colors hover:border-line-strong hover:bg-surface-2"
      >
        Log in
      </button>
    )
  }

  return (
    <div ref={container} className="relative shrink-0">
      <button
        type="button"
        onClick={() => setOpen((wasOpen) => !wasOpen)}
        aria-expanded={open}
        aria-haspopup="menu"
        className="flex cursor-pointer items-center gap-2 rounded-lg py-1 pr-2 pl-1 transition-colors hover:bg-surface-2"
      >
        <Avatar user={user} />
        <span className="hidden max-w-32 truncate text-sm sm:block">{user.name}</span>
      </button>

      {open && (
        <div
          role="menu"
          className="absolute right-0 z-30 mt-1.5 w-56 overflow-hidden rounded-lg border border-line bg-surface shadow-xl"
        >
          <div className="border-b border-line px-3 py-2.5">
            <p className="truncate text-sm font-medium">{user.name}</p>
            <p className="truncate text-xs text-subtle">
              {user.email ?? `Signed in with ${user.providers?.[0] ?? 'a provider'}`}
            </p>
          </div>

          <Link
            href="/favorites"
            role="menuitem"
            onClick={() => setOpen(false)}
            className="flex w-full cursor-pointer items-center gap-2 border-b border-line px-3 py-2.5 text-left text-sm transition-colors hover:bg-surface-2"
          >
            <Icon.star className="size-4 text-subtle" />
            Favorite servers
          </Link>

          <button
            type="button"
            role="menuitem"
            onClick={() => {
              setOpen(false)
              signOut()
            }}
            className="flex w-full cursor-pointer items-center gap-2 px-3 py-2.5 text-left text-sm transition-colors hover:bg-surface-2"
          >
            <Icon.logout className="size-4 text-subtle" />
            Sign out
          </button>
        </div>
      )}
    </div>
  )
}

function Avatar({ user }: { user: { name: string; avatar: string | null } }) {
  if (user.avatar) {
    return (
      <img
        src={user.avatar}
        alt=""
        width={28}
        height={28}
        className="size-7 shrink-0 rounded-full border border-line object-cover"
      />
    )
  }

  return (
    <span
      aria-hidden
      className="font-display flex size-7 shrink-0 items-center justify-center rounded-full bg-surface-2 text-xs font-bold text-muted"
    >
      {user.name.slice(0, 1).toUpperCase()}
    </span>
  )
}
