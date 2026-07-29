'use client'

import Link from 'next/link'
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import { Icon } from './icons'

/**
 * Transient messages, top right.
 *
 * Everything that happens because somebody pressed something — a code sent, an
 * address refused, a server added, a link copied — is said here rather than in
 * a paragraph wired into whichever form raised it. That keeps forms about their
 * fields, and it means the same sentence looks the same wherever it comes from.
 *
 * What stays inline is state, not events: a field that failed validation keeps
 * its red border, and a panel that knows you already voted today keeps saying
 * so. A toast is for what just happened, not for what is true.
 */

export type ToastAction = { href: string; label: string }

/**
 * Red for something that went wrong, green for something that worked, and a
 * neutral third for something that is merely so. "This server is already
 * listed" is not a failure — the address was right and the thing the visitor
 * wanted is already true — and colouring it as one blames them for it.
 */
type Kind = 'error' | 'success' | 'info'

type Toast = {
  id: number
  kind: Kind
  title: string
  message?: string
  action?: ToastAction
}

type ToastApi = {
  error: (title: string, message?: string, action?: ToastAction) => void
  success: (title: string, message?: string, action?: ToastAction) => void
  info: (title: string, message?: string, action?: ToastAction) => void
}

const ToastContext = createContext<ToastApi | null>(null)

export function useToast(): ToastApi {
  const api = useContext(ToastContext)

  if (api === null) {
    throw new Error('useToast must be used inside <ToastProvider>')
  }

  return api
}

/** Four is already more than anyone reads; older ones make way. */
const MAX_VISIBLE = 4

// Module scope, not a ref: an id only has to be unique among the toasts alive
// at one moment, and there is exactly one viewport on the page.
let nextId = 0

export function ToastProvider({ children }: { children: React.ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([])
  const viewport = useRef<HTMLDivElement>(null)

  const dismiss = useCallback((id: number) => {
    setToasts((list) => list.filter((toast) => toast.id !== id))
  }, [])

  const api = useMemo<ToastApi>(() => {
    const push = (kind: Kind) => (title: string, message?: string, action?: ToastAction) =>
      setToasts((list) => [...list, { id: nextId++, kind, title, message, action }].slice(-MAX_VISIBLE))

    return { error: push('error'), success: push('success'), info: push('info') }
  }, [])

  // Promotion into the top layer, redone on every change: a toast raised while
  // the sign-in dialog is open has to sit above it, and the last element
  // promoted is the one on top.
  useEffect(() => {
    const element = viewport.current

    if (!element || typeof element.showPopover !== 'function') return

    if (element.matches(':popover-open')) element.hidePopover()
    if (toasts.length > 0) element.showPopover()
  }, [toasts])

  return (
    <ToastContext.Provider value={api}>
      {children}

      <div
        ref={viewport}
        popover="manual"
        role="region"
        aria-label="Notifications"
        // Positioned in globals.css, next to the popover reset that would
        // otherwise override any placement utilities put here.
        className="pointer-events-none z-50 flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-3"
      >
        {toasts.map((toast) => (
          <ToastCard key={toast.id} toast={toast} onDismiss={() => dismiss(toast.id)} />
        ))}
      </div>
    </ToastContext.Provider>
  )
}

/** Surface, the mark, and how the action reads against that surface. */
const LOOKS = {
  error: {
    card: 'bg-danger text-white',
    mark: Icon.alert,
    message: 'text-white/90',
    action: 'bg-white/20 text-white hover:bg-white/30',
    close: 'text-white/80 hover:text-white',
  },
  success: {
    card: 'bg-brand text-white',
    mark: Icon.check,
    message: 'text-white/90',
    action: 'bg-white/20 text-white hover:bg-white/30',
    close: 'text-white/80 hover:text-white',
  },
  info: {
    card: 'border border-line-strong bg-surface-2 text-fg',
    mark: Icon.info,
    message: 'text-muted',
    action: 'bg-brand text-white hover:bg-brand-strong',
    close: 'text-subtle hover:text-fg',
  },
} as const

function ToastCard({ toast, onDismiss }: { toast: Toast; onDismiss: () => void }) {
  const [paused, setPaused] = useState(false)
  const failed = toast.kind === 'error'
  const look = LOOKS[toast.kind]

  useEffect(() => {
    if (paused) return

    // A toast carrying a link has to outlive the urge to read it first.
    const timer = setTimeout(onDismiss, toast.action ? 10_000 : failed ? 5_000 : 3_000)

    return () => clearTimeout(timer)
  }, [paused, failed, toast.action, onDismiss])

  const Mark = look.mark

  return (
    <div
      // Errors interrupt, everything else waits its turn — the difference
      // between a screen reader speaking now and speaking at the next pause.
      role={failed ? 'alert' : 'status'}
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocusCapture={() => setPaused(true)}
      onBlurCapture={() => setPaused(false)}
      className={`toast-enter pointer-events-auto flex items-start gap-3 rounded-2xl px-5 py-4 shadow-xl ${look.card}`}
    >
      <Mark className="mt-0.5 size-5 shrink-0" />

      <div className="min-w-0 flex-1">
        <p className="font-bold">{toast.title}</p>
        {toast.message && <p className={`mt-0.5 text-sm ${look.message}`}>{toast.message}</p>}
        {toast.action && (
          /* A button rather than an underlined word: this is the next step, and
             on a toast that disappears it has to be seen and hit quickly. */
          <Link
            href={toast.action.href}
            onClick={onDismiss}
            className={`mt-2.5 inline-block cursor-pointer rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${look.action}`}
          >
            {toast.action.label}
          </Link>
        )}
      </div>

      <button
        type="button"
        onClick={onDismiss}
        aria-label="Dismiss"
        className={`-mt-1 -mr-1 shrink-0 cursor-pointer rounded p-1 transition-colors ${look.close}`}
      >
        <Icon.close className="size-5" />
      </button>
    </div>
  )
}
