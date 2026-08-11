import type { Metadata } from 'next'
import { ContactForm } from '@/components/contact-form'
import { DISCORD_URL, RESPONSE_SLA, SUPPORT_EMAIL, TELEGRAM_URL } from '@/lib/contact'
import { CONTROLLER } from '@/lib/legal'
import { canonical } from '@/lib/seo'

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'

export const metadata: Metadata = {
  title: 'Contact',
  description:
    'Reach LobbyHub about a listing, a bug, or a partnership. Email, Discord and Telegram; reply within one business day.',
  ...canonical('/contact'),
}

/**
 * Four rows and a form.
 *
 * The channels sit above the form, not beside it, because that is the order
 * of what people actually want: most visitors would rather write to a person
 * on Discord than fill in a five-field form, and burying the invite under a
 * form makes them use the form instead. The form is what stays for the case
 * everything else does not fit — the one-off bug report, the partnership
 * question, the note about a server we cannot list.
 *
 * The company address at the bottom is what makes this page legally count:
 * ePrivacy and consumer-protection rules across the EU require a postal
 * contact that is visible on the site itself, not only in the privacy notice.
 * Kept small because it is a reference, not a message.
 */
export default function ContactPage() {
  return (
    <div className="mx-auto max-w-3xl space-y-10">
      <header className="space-y-2">
        <h1 className="font-display text-3xl font-black tracking-tight">Contact</h1>
        <p className="text-subtle">
          Bug, listing, partnership, anything else — one of these will reach us.{' '}
          {RESPONSE_SLA}
        </p>
      </header>

      <section aria-labelledby="channels" className="space-y-3">
        <h2 id="channels" className="sr-only">
          Channels
        </h2>

        <Channel
          label="Email"
          value={SUPPORT_EMAIL}
          href={`mailto:${SUPPORT_EMAIL}`}
          hint="Best for anything that needs an attachment or a long reply thread."
        />

        <Channel
          label="Discord"
          value="Join the LobbyHub server"
          href={DISCORD_URL}
          hint="Fastest during working hours."
          external
        />

        {TELEGRAM_URL && (
          <Channel
            label="Telegram"
            value="Chat with us on Telegram"
            href={TELEGRAM_URL}
            hint="Same team, another window."
            external
          />
        )}
      </section>

      <section aria-labelledby="form" className="space-y-4">
        <h2 id="form" className="font-display text-xl font-black tracking-tight">
          Or write to us here
        </h2>
        <p className="text-sm text-subtle">
          Your reply goes to the email you type, not to any account you may be signed in with —
          so this form works even if you are not signed in.
        </p>

        <ContactForm apiUrl={API_URL} />
      </section>

      <section aria-labelledby="company" className="rounded-2xl border border-line bg-surface p-5 text-sm">
        <h2
          id="company"
          className="font-display mb-2 text-[11px] font-bold tracking-widest text-fg uppercase"
        >
          Registered contact
        </h2>
        <p className="text-subtle">
          <span className="text-fg">{CONTROLLER.name}</span>
          <br />
          {CONTROLLER.address}
        </p>
      </section>
    </div>
  )
}

function Channel({
  label,
  value,
  href,
  hint,
  external,
}: {
  label: string
  value: string
  href: string
  hint: string
  external?: boolean
}) {
  return (
    <a
      href={href}
      target={external ? '_blank' : undefined}
      rel={external ? 'noopener noreferrer' : undefined}
      className="flex items-start justify-between gap-4 rounded-xl border border-line bg-surface p-4 transition-colors hover:border-brand"
    >
      <div>
        <p className="font-display text-[11px] font-bold tracking-widest text-subtle uppercase">
          {label}
        </p>
        <p className="mt-1 text-base font-medium text-fg">{value}</p>
        <p className="mt-1 text-xs text-subtle">{hint}</p>
      </div>
    </a>
  )
}
