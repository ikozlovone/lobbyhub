'use client'

import { useState } from 'react'
import { submitServer, type Game, type Submission } from '@/lib/api'
import { Icon } from './icons'
import { useToast } from './toast'

/**
 * The whole submission form: one address, one optional port.
 *
 * Nothing else is asked for. Name, players, map and version are read off the
 * server during verification — a form that let an owner type those in would be
 * asking them for the numbers we exist to measure.
 */
export function AddServerForm({ game, apiUrl }: { game: Game; apiUrl: string }) {
  const [address, setAddress] = useState('')
  const [queryPort, setQueryPort] = useState('')
  const [busy, setBusy] = useState(false)
  // Only whether the address was refused, to colour the field. What was wrong
  // with it is said by the toast.
  const [rejected, setRejected] = useState(false)
  const toast = useToast()

  const defaultPort = game.monitoring.default_port

  async function submit(event: React.FormEvent) {
    event.preventDefault()

    if (busy || address.trim() === '') return

    setBusy(true)
    setRejected(false)

    const outcome = await submitServer(apiUrl, game.slug, {
      address: address.trim(),
      query_port: queryPort.trim() === '' ? null : Number(queryPort.trim()),
    })

    setBusy(false)
    announce(outcome)
  }

  function announce(outcome: Submission) {
    // Both of the first two leave the form empty. In one case the server was
    // just added and in the other it was already there — either way the address
    // in the box is done with, and leaving it sitting invites a second attempt
    // at something that has already happened.
    if (outcome.status !== 'error') {
      setAddress('')
      setQueryPort('')
    }

    const goTo = (slug: string) => ({ href: `/servers/${slug}`, label: 'Go to server' })

    switch (outcome.status) {
      case 'created':
        // The link is why this toast lives longer than the others: the server
        // page it points at is the whole reason anyone filled the form in.
        return toast.success(
          `${outcome.server.name} answered — it is in.`,
          outcome.message,
          goTo(outcome.server.slug),
        )

      case 'listed':
        // Not an error and not red: the address was right, and what they wanted
        // is already true. All that is left to do is show them where it lives.
        return toast.info(
          'Already in the catalog',
          `${outcome.server.name} is listed already.`,
          goTo(outcome.server.slug),
        )

      case 'error':
        setRejected(true)

        return toast.error('Error', outcome.error)
    }
  }

  return (
    <div className="space-y-4">
      <form onSubmit={submit} noValidate className="grid gap-3 sm:grid-cols-2">
        <Field
          id="server-address"
          label="Address"
          hint="What players connect to"
          icon={<Icon.globe className="size-4" />}
          value={address}
          onChange={setAddress}
          placeholder={`IP address and port (127.0.0.1:${defaultPort})`}
          invalid={rejected}
          autoFocus
        />

        <Field
          id="server-query-port"
          label="Query port"
          hint="Only if it differs from the game port"
          icon={<Icon.gauge className="size-4" />}
          value={queryPort}
          onChange={(value) => setQueryPort(value.replace(/\D/g, '').slice(0, 5))}
          placeholder={
            game.monitoring.default_query_port
              ? `Usually ${game.monitoring.default_query_port}`
              : 'Usually the same as the game port'
          }
          inputMode="numeric"
        />

        <div className="sm:col-span-2">
          <button
            type="submit"
            disabled={busy || address.trim() === ''}
            className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-lg bg-brand px-4 py-3 font-medium text-white transition-colors hover:bg-brand-strong disabled:cursor-not-allowed disabled:bg-surface-2 disabled:text-subtle sm:w-auto"
          >
            <Icon.plus className="size-4" />
            {busy ? 'Checking the server…' : 'Add server'}
          </button>

          <p className="mt-2 text-xs text-subtle">
            {busy
              ? `Querying the address over ${game.monitoring.protocol_label}. This takes a few seconds.`
              : 'We query the address before listing it, so nothing gets added that we cannot reach.'}
          </p>
        </div>
      </form>
    </div>
  )
}

function Field({
  id,
  label,
  hint,
  icon,
  value,
  onChange,
  placeholder,
  invalid,
  ...rest
}: {
  id: string
  label: string
  hint: string
  icon: React.ReactNode
  value: string
  onChange: (value: string) => void
  placeholder: string
  invalid?: boolean
} & Pick<React.ComponentProps<'input'>, 'inputMode' | 'autoFocus'>) {
  return (
    <div>
      <label htmlFor={id} className="block text-xs text-subtle">
        {label} <span className="text-subtle/70">— {hint}</span>
      </label>
      <div className="relative mt-1">
        <span aria-hidden className="absolute top-1/2 left-3 -translate-y-1/2 text-subtle">
          {icon}
        </span>
        <input
          {...rest}
          id={id}
          value={value}
          onChange={(event) => onChange(event.target.value)}
          placeholder={placeholder}
          autoComplete="off"
          spellCheck={false}
          aria-invalid={invalid}
          className={`tabular w-full rounded-lg border bg-bg py-3 pr-3 pl-9 text-sm outline-none transition-colors placeholder:font-sans placeholder:text-subtle ${
            invalid ? 'border-accent' : 'border-line'
          }`}
        />
      </div>
    </div>
  )
}
