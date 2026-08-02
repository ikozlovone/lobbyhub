'use client'

import Link from 'next/link'
import { useEffect, useRef, useState } from 'react'
import { requestCode, verifyCode, type AuthProviderInfo } from '@/lib/auth'
import { Icon } from '../icons'
import { useToast } from '../toast'

/** Where to come back to after a provider round trip. Read by /auth/callback. */
export const RETURN_KEY = 'lobbyhub.return-to'

/** Digits in a sign-in code, as minted by the API. */
const CODE_LENGTH = 6

/**
 * Sign in and sign up, in one form, because they are the same act: prove you
 * hold a mailbox or a provider account. There is no password, so there is
 * nothing to confirm, recover or choose — which is why this is two screens and
 * not five.
 *
 * The form is separate from the dialog that usually carries it because it is
 * also the whole content of a page: somebody who follows a link to their
 * favourites while signed out should be able to sign in where they landed,
 * rather than be shown an empty page with a button that opens a modal.
 */
export function SignInForm({
  apiUrl,
  providers,
  onSignedIn,
  headingId,
  title = 'Sign in to unlock all LobbyHub features',
}: {
  apiUrl: string
  providers: AuthProviderInfo[]
  onSignedIn: (token: string) => void
  /** Set when a container labels itself by this heading, as a dialog does. */
  headingId?: string
  title?: string
}) {
  const [step, setStep] = useState<'email' | 'code'>('email')
  const [email, setEmail] = useState('')
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  // Kept only to colour the field it belongs to — the sentence itself is said
  // by the toast, so it is not repeated under the form.
  const [rejected, setRejected] = useState(false)
  const [resendIn, setResendIn] = useState(0)
  const toast = useToast()

  useEffect(() => {
    if (resendIn <= 0) return

    const timer = setInterval(() => setResendIn((seconds) => Math.max(0, seconds - 1)), 1000)

    return () => clearInterval(timer)
  }, [resendIn])

  async function sendCode(address: string) {
    setBusy(true)
    setRejected(false)

    const result = await requestCode(apiUrl, address)

    setBusy(false)

    if (!result.ok) {
      setRejected(true)
      toast.error('Error', result.error)

      return
    }

    setStep('code')
    setResendIn(result.resendIn)
    toast.success('Code sent', `Six digits are on their way to ${address}.`)
  }

  async function submitCode(value: string) {
    setBusy(true)
    setRejected(false)

    const result = await verifyCode(apiUrl, email.trim(), value)

    setBusy(false)

    if (!result.ok) {
      setRejected(true)
      setCode('')
      toast.error('Error', result.error)

      return
    }

    onSignedIn(result.token)
  }

  if (step === 'email') {
    return (
      <>
        <h2 id={headingId} className="mt-4 text-center text-xl font-bold sm:text-2xl">
          {title}
        </h2>

        <form
          noValidate
          className="mt-6 space-y-3"
          onSubmit={(event) => {
            event.preventDefault()
            if (!busy && email.trim() !== '') void sendCode(email.trim().toLowerCase())
          }}
        >
          <div className="relative">
            <label htmlFor="auth-email" className="sr-only">
              Email address
            </label>
            <span aria-hidden className="absolute top-1/2 left-4 -translate-y-1/2 text-subtle">
              <Icon.mail className="size-5" />
            </span>
            <input
              id="auth-email"
              type="email"
              autoComplete="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              placeholder="Indicate your email"
              aria-invalid={rejected}
              className={`w-full rounded-xl border bg-bg py-3.5 pr-4 pl-12 outline-none transition-colors placeholder:text-subtle ${
                rejected ? 'border-accent' : 'border-line'
              }`}
            />
          </div>

          <button
            type="submit"
            disabled={busy || email.trim() === ''}
            className="w-full cursor-pointer rounded-xl bg-brand py-3.5 font-medium text-white transition-colors hover:bg-brand-strong disabled:cursor-not-allowed disabled:bg-surface-2 disabled:text-subtle"
          >
            {busy ? 'Sending a code…' : 'Continue'}
          </button>
        </form>

        {providers.length > 0 && (
          <>
            <div className="my-6 flex items-center gap-3 text-[11px] tracking-[0.2em] text-subtle">
              <span className="h-px flex-1 bg-line" />
              OR
              <span className="h-px flex-1 bg-line" />
            </div>

            <div className="space-y-2.5">
              {providers.map((provider) => (
                <ProviderButton key={provider.key} provider={provider} />
              ))}
            </div>
          </>
        )}

        <Terms />
      </>
    )
  }

  return (
    <>
      <h2 id={headingId} className="mt-4 text-center text-xl font-bold sm:text-2xl">
        Enter the code we sent
      </h2>
      <p className="mt-2 text-center text-sm text-muted">
        Six digits are on their way to <span className="text-fg">{email}</span>. The code works
        once and expires shortly.
      </p>

      <form
        noValidate
        className="mt-6 space-y-3"
        onSubmit={(event) => {
          event.preventDefault()
          if (!busy && code.length === CODE_LENGTH) void submitCode(code)
        }}
      >
        <CodeInput
          value={code}
          // Retyping is the answer to a rejected code, so the boxes stop being
          // red the moment it starts rather than at the next attempt.
          onChange={(value) => {
            setCode(value)
            setRejected(false)
          }}
          // The last digit is the whole intent: nobody types six characters and
          // then wants to be asked again by pressing a button.
          onComplete={(value) => {
            if (!busy) void submitCode(value)
          }}
          invalid={rejected}
          disabled={busy}
        />

        <button
          type="submit"
          disabled={busy || code.length < CODE_LENGTH}
          className="w-full cursor-pointer rounded-xl bg-brand py-3.5 font-medium text-white transition-colors hover:bg-brand-strong disabled:cursor-not-allowed disabled:bg-surface-2 disabled:text-subtle"
        >
          {busy ? 'Checking…' : 'Sign in'}
        </button>
      </form>

      <div className="mt-4 flex items-center justify-between text-sm">
        <button
          type="button"
          onClick={() => {
            setStep('email')
            setRejected(false)
          }}
          className="cursor-pointer text-subtle transition-colors hover:text-fg"
        >
          Use another address
        </button>

        <button
          type="button"
          disabled={busy || resendIn > 0}
          onClick={() => void sendCode(email)}
          className="cursor-pointer text-brand transition-colors hover:underline disabled:cursor-not-allowed disabled:text-subtle disabled:no-underline"
        >
          {resendIn > 0 ? `Resend in ${resendIn}s` : 'Send another code'}
        </button>
      </div>

      <Terms />
    </>
  )
}

function Terms() {
  return (
    <p className="mt-6 text-center text-xs leading-relaxed text-subtle">
      By continuing you agree that we may store your address to keep you signed in and to contact
      you about servers you claim. Nothing else. See the{' '}
      {/* A new tab, not a navigation: this sits inside the sign-in dialog, and
          following it in place throws away the code somebody is halfway
          through typing. */}
      <Link href="/terms" target="_blank" className="underline hover:text-muted">
        terms of use
      </Link>
      .
    </p>
  )
}

/**
 * One box per digit.
 *
 * The code is still a single string — the boxes are a view of it, not six
 * independent fields — so it can only ever be filled from the left and can never
 * hold a gap. That is the whole trick: focus follows the string's length, so
 * clicking the fifth box when two digits are typed puts the caret on the third,
 * and there is no state where "complete" is ambiguous.
 *
 * A pasted or autofilled code arrives as several characters in one event, which
 * is why each box writes a run rather than a single character.
 */
function CodeInput({
  value,
  onChange,
  onComplete,
  invalid,
  disabled,
}: {
  value: string
  onChange: (value: string) => void
  onComplete: (value: string) => void
  invalid: boolean
  disabled: boolean
}) {
  const boxes = useRef<(HTMLInputElement | null)[]>([])
  // Focus moves in the same event that changes the code, before React has
  // re-rendered with it, so the box being focused would otherwise judge itself
  // against the previous code and bounce the caret back a slot.
  const entered = useRef(value)

  useEffect(() => {
    entered.current = value
  }, [value])

  const focusAt = (index: number) =>
    boxes.current[Math.min(Math.max(index, 0), CODE_LENGTH - 1)]?.focus()

  function commit(next: string, caret: number) {
    entered.current = next
    onChange(next)
    focusAt(caret)

    if (next.length === CODE_LENGTH) onComplete(next)
  }

  /** A rejected code is cleared, and the visitor should be able to just retype. */
  useEffect(() => {
    if (invalid) boxes.current[0]?.focus()
  }, [invalid])

  return (
    <div className="flex justify-between gap-2">
      {Array.from({ length: CODE_LENGTH }, (_, index) => (
        <input
          key={index}
          ref={(element) => {
            boxes.current[index] = element
          }}
          type="text"
          inputMode="numeric"
          // Only on the first box: the browser fills the field it is offered
          // against, and a code split six ways would land entirely in the last.
          autoComplete={index === 0 ? 'one-time-code' : 'off'}
          autoFocus={index === 0}
          disabled={disabled}
          aria-label={`Digit ${index + 1} of ${CODE_LENGTH}`}
          aria-invalid={invalid}
          value={value[index] ?? ''}
          // Reaching past the end of the code would leave a gap, so the caret is
          // pulled back to the first empty box instead.
          onFocus={(event) => {
            if (index > entered.current.length) focusAt(entered.current.length)
            else event.currentTarget.select()
          }}
          onChange={(event) => {
            const digits = event.target.value.replace(/\D/g, '')

            if (digits === '') return

            const current = entered.current
            const at = Math.min(index, current.length)
            const next = (current.slice(0, at) + digits).slice(0, CODE_LENGTH)

            commit(next + current.slice(next.length), next.length)
          }}
          onKeyDown={(event) => {
            if (event.key === 'Backspace') {
              event.preventDefault()

              const current = entered.current
              const at = current[index] ? index : index - 1

              if (at < 0) return

              commit(current.slice(0, at) + current.slice(at + 1), at)
            }

            if (event.key === 'ArrowLeft') {
              event.preventDefault()
              focusAt(index - 1)
            }

            if (event.key === 'ArrowRight') {
              event.preventDefault()
              focusAt(Math.min(index + 1, entered.current.length))
            }
          }}
          onPaste={(event) => {
            event.preventDefault()

            const pasted = event.clipboardData.getData('text').replace(/\D/g, '')

            if (pasted === '') return

            const current = entered.current
            const at = Math.min(index, current.length)
            const next = (current.slice(0, at) + pasted).slice(0, CODE_LENGTH)

            commit(next + current.slice(next.length), next.length)
          }}
          className={`tabular h-14 w-full min-w-0 rounded-xl border bg-bg text-center text-2xl outline-none transition-colors focus:border-brand disabled:text-subtle ${
            invalid ? 'border-accent' : 'border-line'
          }`}
        />
      ))}
    </div>
  )
}

const MARKS = { steam: Icon.steam, discord: Icon.discord, google: Icon.google }

const BUTTON =
  'flex w-full cursor-pointer items-center justify-center gap-2.5 rounded-xl border border-line bg-bg py-3.5 font-medium transition-colors'

/**
 * One provider.
 *
 * Every method we support is shown, including any this deployment has no
 * credentials for. Those are dimmed and say so when pressed rather than being
 * left out: a missing button and a broken one look identical to a visitor, and
 * following the link would only land them on Discord's own error page.
 */
function ProviderButton({ provider }: { provider: AuthProviderInfo }) {
  const Mark = MARKS[provider.key]
  const toast = useToast()

  if (!provider.available) {
    return (
      <button
        type="button"
        // Not marked disabled, in either sense: pressing it is how the visitor
        // finds out why it is dim, and a control that announces itself disabled
        // and then responds is telling two different stories. The state rides in
        // the accessible name instead, which still contains the visible label.
        aria-label={`Sign in with ${provider.label} — not available yet`}
        onClick={() =>
          toast.error(
            `${provider.label} sign-in is not set up`,
            'It has not been switched on for this site yet — the email code above works right now.',
          )
        }
        className={`${BUTTON} text-muted opacity-55 hover:border-line-strong`}
      >
        <Mark className="size-5" />
        Sign in with {provider.label}
      </button>
    )
  }

  return (
    <a
      href={provider.url}
      onClick={() => {
        // The provider sends the browser back to /auth/callback, which has no
        // idea where the visitor came from unless we leave a note.
        sessionStorage.setItem(RETURN_KEY, window.location.pathname + window.location.search)
      }}
      className={`${BUTTON} hover:border-line-strong hover:bg-surface-2`}
    >
      <Mark className="size-5" />
      Sign in with {provider.label}
    </a>
  )
}
