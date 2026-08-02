import Link from 'next/link'
import { CONTROLLER, LEGAL_UNSET } from '@/lib/legal'

/**
 * The shell the terms and the privacy notice share.
 *
 * Both are prose rather than listing chrome, so both set a measure instead of
 * filling the column, and both carry the same warning when the identity behind
 * them has not been filled in.
 */
export function LegalPage({
  title,
  updated,
  children,
}: {
  title: string
  updated: string
  children: React.ReactNode
}) {
  return (
    <div className="space-y-6">
      <header className="space-y-2">
        <nav aria-label="Breadcrumb" className="text-xs text-subtle">
          <Link href="/" className="hover:text-fg">
            LobbyHub
          </Link>
          <span className="mx-1.5">/</span>
          <span className="text-muted">{title}</span>
        </nav>
        <h1 className="font-display text-2xl font-black tracking-tight uppercase sm:text-3xl">
          {title}
        </h1>
        <p className="text-sm text-subtle">Last updated {updated}.</p>
      </header>

      {/* Loud on purpose. A privacy notice naming nobody is not a lesser notice,
          it is one that fails the disclosure it exists to make — and the failure
          is invisible unless the page says so. */}
      {LEGAL_UNSET && (
        <p className="max-w-[68ch] rounded-lg border border-accent/40 bg-accent/10 p-3 text-sm text-fg">
          <strong className="font-medium">This deployment is not configured.</strong> The name,
          postal address, contact address or hosting country below is still a placeholder, and the
          page is not valid until they are set. See <code>.env.example</code>.
        </p>
      )}

      <div className="max-w-[68ch] space-y-8 text-sm leading-relaxed text-muted">{children}</div>
    </div>
  )
}

export function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="space-y-3">
      <h2 className="font-display text-sm font-bold tracking-wide text-fg uppercase">{title}</h2>
      {children}
    </section>
  )
}

/** The contact address, as a link. Written once because both pages promise an answer at it. */
export function Mail() {
  return (
    <a href={`mailto:${CONTROLLER.email}`} className="text-brand hover:underline">
      {CONTROLLER.email}
    </a>
  )
}
