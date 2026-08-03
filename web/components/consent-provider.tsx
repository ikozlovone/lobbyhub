'use client'

import Link from 'next/link'
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import {
  CATEGORY_LABELS,
  CONFIGURED,
  readConsent,
  writeConsent,
  type ConsentCategory,
} from '@/lib/consent'

/**
 * The consent gate, for the whole app.
 *
 * Nothing that needs permission may run outside `<ConsentGate>`, and the answer
 * starts at no. See lib/consent.ts for why the default matters.
 */

type ConsentState = {
  /** False until storage has been read, so nothing decides "denied" before the answer is known. */
  ready: boolean
  granted: (category: ConsentCategory) => boolean
  /** Reopen the choice — the withdrawal route the GDPR requires to be as easy as giving it. */
  open: () => void
}

const ConsentContext = createContext<ConsentState | null>(null)

export function useConsent(): ConsentState {
  const context = useContext(ConsentContext)

  if (context === null) {
    throw new Error('useConsent must be used inside <ConsentProvider>')
  }

  return context
}

/**
 * Renders its children only once this visitor has allowed that purpose.
 *
 * An ad slot or a tracking tag goes inside one of these. It renders nothing —
 * not a placeholder, not a "enable ads to see this" nag — when the answer is no
 * or not yet given: a page that degrades when you refuse is a page that punishes
 * refusing, which makes the consent not freely given.
 */
export function ConsentGate({
  category,
  children,
}: {
  category: ConsentCategory
  children: React.ReactNode
}) {
  const { ready, granted } = useConsent()

  if (!ready || !granted(category)) return null

  return <>{children}</>
}

export function ConsentProvider({ children }: { children: React.ReactNode }) {
  const [ready, setReady] = useState(false)
  const [granted, setGranted] = useState<ConsentCategory[]>([])
  /** 'closed' — nothing shown. 'banner' — first ask. 'details' — the per-purpose dialog. */
  const [ask, setAsk] = useState<'closed' | 'banner' | 'details'>('closed')

  // Read after mount, not during render: localStorage does not exist on the
  // server, and a banner in the prerendered HTML would be a banner shown for a
  // moment to somebody who already answered.
  //
  // Resolved through a promise so the state lands after the effect rather than
  // during it — same reason as the session restore in auth-provider: a
  // synchronous setState here re-renders the whole tree under the provider.
  useEffect(() => {
    let cancelled = false

    Promise.resolve(readConsent()).then((stored) => {
      if (cancelled) return

      if (stored) setGranted(stored.granted)

      setReady(true)

      // Nothing configured means nothing to ask about — see CONFIGURED.
      if (!stored && CONFIGURED.length > 0) setAsk('banner')
    })

    return () => {
      cancelled = true
    }
  }, [])

  const decide = useCallback((categories: ConsentCategory[]) => {
    setGranted(writeConsent(categories, new Date()).granted)
    setAsk('closed')
  }, [])

  const open = useCallback(() => setAsk('details'), [])

  const value = useMemo<ConsentState>(
    () => ({
      ready,
      granted: (category) => granted.includes(category),
      open,
    }),
    [ready, granted, open],
  )

  return (
    <ConsentContext.Provider value={value}>
      {children}

      {ask === 'banner' && (
        <ConsentBanner
          onAcceptAll={() => decide(CONFIGURED)}
          onRejectAll={() => decide([])}
          onCustomise={() => setAsk('details')}
        />
      )}

      <ConsentDialog
        open={ask === 'details'}
        granted={granted}
        onSave={decide}
        onClose={() => setAsk(ask === 'details' && readConsent() === null ? 'banner' : 'closed')}
      />
    </ConsentContext.Provider>
  )
}

/**
 * The first ask.
 *
 * Accept and reject sit side by side, same size, same weight. That is not a
 * style choice: a reject button that is smaller, greyer or one level deeper than
 * accept is the specific dark pattern EU regulators have been fining, because a
 * choice that is harder to decline is not freely given.
 */
function ConsentBanner({
  onAcceptAll,
  onRejectAll,
  onCustomise,
}: {
  onAcceptAll: () => void
  onRejectAll: () => void
  onCustomise: () => void
}) {
  return (
    <div
      role="dialog"
      aria-modal="false"
      aria-labelledby="consent-banner-title"
      className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 p-4 backdrop-blur"
    >
      <div className="mx-auto flex w-full max-w-[100rem] flex-col gap-4 sm:flex-row sm:items-center">
        <div className="min-w-0 flex-1 text-sm">
          <p id="consent-banner-title" className="font-medium text-fg">
            Cookies and similar storage
          </p>
          <p className="mt-1 text-muted">
            We need what keeps you signed in — that one has no choice attached. Everything else is
            up to you.{' '}
            <Link href="/privacy" prefetch={false} className="text-brand hover:underline">
              What we store and why
            </Link>
            .
          </p>
        </div>

        <div className="flex shrink-0 flex-wrap gap-2">
          <button
            type="button"
            onClick={onRejectAll}
            className="flex-1 cursor-pointer rounded-lg border border-line-strong px-4 py-2 text-sm font-medium text-fg transition-colors hover:bg-surface-2 sm:flex-none"
          >
            Reject all
          </button>
          <button
            type="button"
            onClick={onAcceptAll}
            className="flex-1 cursor-pointer rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-brand-strong sm:flex-none"
          >
            Accept all
          </button>
          <button
            type="button"
            onClick={onCustomise}
            className="cursor-pointer px-2 py-2 text-sm text-muted underline transition-colors hover:text-fg"
          >
            Choose
          </button>
        </div>
      </div>
    </div>
  )
}

/**
 * The per-purpose choice, and the way back to it afterwards.
 *
 * Built on <dialog> for the same reasons as the sign-in modal: focus trapping,
 * Escape and the backdrop are browser behaviour worth not reimplementing.
 */
function ConsentDialog({
  open,
  granted,
  onSave,
  onClose,
}: {
  open: boolean
  granted: ConsentCategory[]
  onSave: (categories: ConsentCategory[]) => void
  onClose: () => void
}) {
  const dialog = useRef<HTMLDialogElement>(null)

  useEffect(() => {
    const element = dialog.current

    if (!element) return

    if (open && !element.open) element.showModal()
    if (!open && element.open) element.close()
  }, [open])

  useEffect(() => {
    const element = dialog.current

    if (!element) return

    const closed = () => onClose()

    element.addEventListener('close', closed)

    return () => element.removeEventListener('close', closed)
  }, [onClose])

  return (
    <dialog
      ref={dialog}
      aria-labelledby="consent-dialog-title"
      onClick={(event) => event.target === dialog.current && onClose()}
      className="m-auto w-[min(32rem,calc(100vw-2rem))] rounded-2xl border border-line bg-surface p-0 text-fg backdrop:bg-black/70 backdrop:backdrop-blur-sm"
    >
      {/*
       * Uncontrolled, and keyed on `open` so it remounts each time it is
       * reopened: the boxes then start from what is actually stored, without a
       * draft in state and without an effect to resync it. Cancelling and
       * reopening cannot show yesterday's half-made edit, because there is
       * nothing to remember it in.
       */}
      <form
        key={String(open)}
        className="space-y-5 p-6 sm:p-8"
        onClick={(event) => event.stopPropagation()}
        onSubmit={(event) => {
          event.preventDefault()
          const picked = new FormData(event.currentTarget).getAll('consent')
          onSave(CONFIGURED.filter((category) => picked.includes(category)))
        }}
      >
        <div className="space-y-1">
          <h2 id="consent-dialog-title" className="font-display text-lg font-black tracking-tight">
            What may we store?
          </h2>
          <p className="text-sm text-muted">
            You can change this at any time — the link is in the footer of every page.
          </p>
        </div>

        <ul className="space-y-3 text-sm">
          {/* Listed but not switchable, because it is not a choice: without it
              the site cannot keep you signed in, which is the thing you asked
              for by signing in. */}
          <li className="rounded-lg border border-line bg-surface-2/40 p-3">
            <div className="flex items-start justify-between gap-3">
              <p className="font-medium text-fg">Strictly necessary</p>
              <span className="shrink-0 text-xs text-subtle">Always on</span>
            </div>
            <p className="mt-1 text-muted">
              Your session token and the page you were on before signing in. Nothing about you goes
              anywhere else, and it is gone when you sign out.
            </p>
          </li>

          {CONFIGURED.map((category) => (
            <li key={category} className="rounded-lg border border-line p-3">
              <label className="flex cursor-pointer items-start gap-3">
                <input
                  type="checkbox"
                  name="consent"
                  value={category}
                  // Never pre-ticked beyond what was actually granted: a box
                  // ticked on first ask is consent nobody gave.
                  defaultChecked={granted.includes(category)}
                  className="mt-0.5 size-4 shrink-0 cursor-pointer accent-brand"
                />
                <span className="min-w-0">
                  <span className="block font-medium text-fg">
                    {CATEGORY_LABELS[category].title}
                  </span>
                  <span className="mt-1 block text-muted">{CATEGORY_LABELS[category].detail}</span>
                </span>
              </label>
            </li>
          ))}
        </ul>

        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            onClick={() => onSave([])}
            className="flex-1 cursor-pointer rounded-lg border border-line-strong px-4 py-2 text-sm font-medium text-fg transition-colors hover:bg-surface-2"
          >
            Reject all
          </button>
          <button
            type="submit"
            className="flex-1 cursor-pointer rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-brand-strong"
          >
            Save choice
          </button>
        </div>
      </form>
    </dialog>
  )
}

/** The footer entry. Withdrawing has to be as easy as giving, so it is on every page. */
export function ConsentSettingsButton({ className }: { className?: string }) {
  const { open } = useConsent()

  return (
    <button type="button" onClick={open} className={className}>
      Cookie settings
    </button>
  )
}
