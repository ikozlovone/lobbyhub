'use client'

import { useEffect, useRef } from 'react'
import type { AuthProviderInfo } from '@/lib/auth'
import { SignInForm } from './sign-in-form'

export { RETURN_KEY } from './sign-in-form'

/**
 * The sign-in form, as a modal.
 *
 * Everything about signing in lives in SignInForm; this is the container for the
 * times it is asked for mid-task — a vote button, a claim link — rather than
 * arrived at. The same form is shown flat on the favourites page.
 *
 * Built on <dialog> deliberately: focus trapping, Escape, inertness of the page
 * behind it and the backdrop are all browser behaviour, and every hand-rolled
 * modal ends up reimplementing them badly.
 */
export function AuthDialog({
  apiUrl,
  providers,
  open,
  onClose,
  onSignedIn,
}: {
  apiUrl: string
  providers: AuthProviderInfo[]
  open: boolean
  onClose: () => void
  onSignedIn: (token: string) => void
}) {
  const dialog = useRef<HTMLDialogElement>(null)

  useEffect(() => {
    const element = dialog.current

    if (!element) return

    if (open && !element.open) element.showModal()
    if (!open && element.open) element.close()
  }, [open])

  // Escape and the backdrop both close a <dialog> without telling React.
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
      aria-labelledby="auth-title"
      // The backdrop is the dialog's own ::backdrop; closing on a click outside
      // the card is done by comparing the target to the dialog itself.
      onClick={(event) => event.target === dialog.current && onClose()}
      className="m-auto w-[min(28rem,calc(100vw-2rem))] rounded-2xl border border-line bg-surface p-0 text-fg backdrop:bg-black/70 backdrop:backdrop-blur-sm"
    >
      <div className="p-6 sm:p-8" onClick={(event) => event.stopPropagation()}>
        <p className="font-display text-center text-lg font-black tracking-tight">
          LOBBY<span className="text-brand">HUB</span>
        </p>

        <SignInForm
          apiUrl={apiUrl}
          providers={providers}
          onSignedIn={onSignedIn}
          headingId="auth-title"
        />
      </div>
    </dialog>
  )
}
