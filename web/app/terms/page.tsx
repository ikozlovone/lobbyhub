import type { Metadata } from 'next'
import Link from 'next/link'
import { canonical } from '@/lib/seo'

/** Where takedowns and deletion requests go. Inlined at build time, like every NEXT_PUBLIC_ var. */
const CONTACT = process.env.NEXT_PUBLIC_CONTACT_EMAIL ?? 'hello@example.com'

const UPDATED = '2 August 2026'

export const metadata: Metadata = {
  title: 'Terms of use',
  description:
    'The rules for using LobbyHub: accounts, listing a server, voting, and what our numbers do and do not promise.',
  ...canonical('/terms'),
}

/**
 * Fully static: nothing here is fetched, so the page is built once and costs no
 * request. It is also the only page written to be read rather than scanned,
 * which is why it sets its own measure instead of filling the column.
 */
export default function TermsPage() {
  return (
    <div className="space-y-6">
      <header className="space-y-2">
        <nav aria-label="Breadcrumb" className="text-xs text-subtle">
          <Link href="/" className="hover:text-fg">
            LobbyHub
          </Link>
          <span className="mx-1.5">/</span>
          <span className="text-muted">Terms of use</span>
        </nav>
        <h1 className="font-display text-2xl font-black tracking-tight uppercase sm:text-3xl">
          Terms of use
        </h1>
        <p className="text-sm text-subtle">Last updated {UPDATED}.</p>
      </header>

      <div className="max-w-[68ch] space-y-8 text-sm leading-relaxed text-muted">
        <Section title="What LobbyHub is">
          <p>
            LobbyHub is a directory and monitor for game servers. We query public servers ourselves
            every few minutes and publish what we get back: player counts, uptime, version, map and
            location. We do not run, host, moderate or sell any of the servers listed here.
          </p>
          <p>
            Using the site means accepting these terms. If you do not accept them, do not use the
            site.
          </p>
        </Section>

        <Section title="Accounts">
          <p>
            You can browse everything without an account. Signing in is needed only to keep
            favorites and to manage servers you added.
          </p>
          <p>
            There are no passwords. You sign in with a one-time code sent to your email address, or
            through Steam, Discord or Google. We store what that method gives us — an address, or a
            provider id, display name and avatar — plus the time you last signed in. We use your
            address to sign you in and to contact you about servers you added. Nothing else.
          </p>
          <p>
            Keep access to your mailbox or provider account: whoever holds it can sign in as you.
            Ask us at <Mail /> and we will delete your account and everything attached to it.
          </p>
        </Section>

        <Section title="Adding a server">
          <p>
            Anyone may submit a server address. By submitting one you confirm that you run it or
            have the operator’s permission to list it, and that its address is meant to be public.
          </p>
          <p>
            We verify a submission by querying the address. What we publish about a server comes
            from those queries, not from the form — you cannot type in a player count. The
            descriptive details you do provide are yours to keep accurate.
          </p>
          <p>Do not submit a server that:</p>
          <ul className="list-disc space-y-1.5 pl-5">
            <li>you have no right to list;</li>
            <li>distributes malware, or exists to steal accounts or payment details;</li>
            <li>hosts content that is illegal where it operates;</li>
            <li>duplicates a listing that already exists.</li>
          </ul>
          <p>
            We can edit, hide or remove any listing, and suspend any account, at our discretion —
            usually because a server stopped answering, because a listing is a duplicate, or because
            of the rules above. We do not owe you a listing.
          </p>
        </Section>

        <Section title="Voting">
          <p>
            You can vote for a server once per day. To enforce that we store a hash of your IP
            address — the hash only, never the address — together with the day. A nickname is
            optional; if you give one it is shown to that server’s operator so they can hand out
            whatever reward they promised. That reward is between you and them; we have no part in
            it.
          </p>
          <p>
            Do not attempt to inflate a vote count, through scripts, proxies, paid votes or anything
            else. We remove votes we believe are fake, and the listings that bought them.
          </p>
        </Section>

        <Section title="What our numbers promise">
          <p>
            Nothing. They are measurements from our own checks, taken at intervals, over a network
            that fails sometimes. A server can be full a second after we saw it empty, and a server
            we could not reach may have been fine for everyone else. Uptime is the share of our
            checks that succeeded — our view of a server, not a fact about it.
          </p>
          <p>
            The site is provided as is. We do not guarantee that it is available, complete or
            correct, and we are not liable for what you lose by relying on it.
          </p>
        </Section>

        <Section title="Other people’s servers">
          <p>
            A listing is not an endorsement. What happens on a server — its rules, its purchases,
            its moderation, what it does with the data you give it — is the operator’s
            responsibility, and any dispute is between you and them. Connecting to a listed server
            is your decision.
          </p>
        </Section>

        <Section title="Our content">
          <p>
            The site, its text and its design are ours. Game and server names, logos and cover art
            belong to their owners and appear here to identify what is being listed. If you own
            something published here and want it taken down, write to us and we will.
          </p>
        </Section>

        <Section title="Changes">
          <p>
            We may change these terms. The date at the top says when they last changed, and
            continuing to use the site after that means accepting the new version.
          </p>
        </Section>

        <Section title="Contact">
          <p>
            Questions, takedowns and deletion requests: <Mail />.
          </p>
        </Section>
      </div>
    </div>
  )
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="space-y-3">
      <h2 className="font-display text-sm font-bold tracking-wide text-fg uppercase">{title}</h2>
      {children}
    </section>
  )
}

function Mail() {
  return (
    <a href={`mailto:${CONTACT}`} className="text-brand hover:underline">
      {CONTACT}
    </a>
  )
}
