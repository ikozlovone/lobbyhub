'use client'

import { useState } from 'react'
import { sendContactMessage } from '@/lib/api'
import { Icon } from './icons'
import { useToast } from './toast'

/**
 * The feedback form.
 *
 * Four fields, and only two are decorative: name is optional (some people
 * write in about a bug without wanting to say who they are), and every reply
 * goes to the address they typed rather than to whatever account may or may
 * not be signed in — so the same box works for a visitor who is not signed
 * in at all.
 *
 * Field-level errors come back from Laravel's validator and are painted
 * beside each input; anything else — connection, rate limit, unknown — goes
 * into a toast. The button says what it is doing during a submit rather than
 * spinning silently, and disables so a jumpy click cannot double-send.
 */
export function ContactForm({ apiUrl }: { apiUrl: string }) {
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [subject, setSubject] = useState('')
  const [message, setMessage] = useState('')
  const [errors, setErrors] = useState<
    Partial<Record<'name' | 'email' | 'subject' | 'message', string>>
  >({})
  const [busy, setBusy] = useState(false)
  const toast = useToast()

  async function submit(event: React.FormEvent) {
    event.preventDefault()

    if (busy) return

    setBusy(true)
    setErrors({})

    const outcome = await sendContactMessage(apiUrl, {
      name: name.trim(),
      email: email.trim(),
      subject: subject.trim(),
      message: message.trim(),
    })

    setBusy(false)

    if (outcome.status === 'sent') {
      setName('')
      setEmail('')
      setSubject('')
      setMessage('')
      toast.success('Message sent', 'We will reply within one business day.')
      return
    }

    setErrors(outcome.fieldErrors ?? {})
    toast.error('Could not send', outcome.error)
  }

  const canSubmit = !busy && email.trim() !== '' && subject.trim() !== '' && message.trim() !== ''

  return (
    <form onSubmit={submit} noValidate className="grid gap-4 sm:grid-cols-2">
      <Field
        id="contact-name"
        label="Your name"
        hint="Optional"
        value={name}
        onChange={setName}
        error={errors.name}
        autoComplete="name"
      />

      <Field
        id="contact-email"
        label="Email"
        hint="Where we will reply"
        type="email"
        value={email}
        onChange={setEmail}
        error={errors.email}
        autoComplete="email"
        required
      />

      <div className="sm:col-span-2">
        <Field
          id="contact-subject"
          label="Subject"
          hint="What this is about, in a line"
          value={subject}
          onChange={setSubject}
          error={errors.subject}
          required
        />
      </div>

      <div className="sm:col-span-2">
        <label htmlFor="contact-message" className="block text-xs text-subtle">
          Message <span className="text-subtle/70">— the more specific, the faster we can help</span>
        </label>
        <textarea
          id="contact-message"
          value={message}
          onChange={(event) => setMessage(event.target.value)}
          rows={8}
          required
          className={`mt-1 block w-full rounded-lg border bg-surface px-3 py-2 text-sm text-fg placeholder:text-subtle/60 focus:border-brand focus:outline-none ${
            errors.message ? 'border-danger' : 'border-line'
          }`}
        />
        {errors.message && <p className="mt-1 text-xs text-danger">{errors.message}</p>}
      </div>

      <div className="sm:col-span-2">
        <button
          type="submit"
          disabled={!canSubmit}
          className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-lg bg-brand px-4 py-3 font-medium text-white transition-colors hover:bg-brand-strong disabled:cursor-not-allowed disabled:bg-surface-2 disabled:text-subtle sm:w-auto"
        >
          <Icon.mail className="size-4" />
          {busy ? 'Sending…' : 'Send message'}
        </button>
      </div>
    </form>
  )
}

function Field({
  id,
  label,
  hint,
  value,
  onChange,
  error,
  type = 'text',
  required,
  autoComplete,
}: {
  id: string
  label: string
  hint: string
  value: string
  onChange: (value: string) => void
  error?: string
  type?: string
  required?: boolean
  autoComplete?: string
}) {
  return (
    <div>
      <label htmlFor={id} className="block text-xs text-subtle">
        {label} <span className="text-subtle/70">— {hint}</span>
      </label>
      <input
        id={id}
        type={type}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        required={required}
        autoComplete={autoComplete}
        className={`mt-1 block w-full rounded-lg border bg-surface px-3 py-2 text-sm text-fg placeholder:text-subtle/60 focus:border-brand focus:outline-none ${
          error ? 'border-danger' : 'border-line'
        }`}
      />
      {error && <p className="mt-1 text-xs text-danger">{error}</p>}
    </div>
  )
}
