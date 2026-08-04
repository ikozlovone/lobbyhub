import type { Metadata } from 'next'
import Link from 'next/link'
import { LegalPage, Mail, Section } from '@/components/legal-page'
import { TERMS_UPDATED } from '@/lib/legal'
import { canonical } from '@/lib/seo'

export const metadata: Metadata = {
  title: 'Terms of use',
  description:
    'The rules for using LobbyHub: accounts, listing a server, voting, advertising, and what our numbers do and do not promise.',
  ...canonical('/terms'),
}

/**
 * The rules. What we do with data is next door, in /privacy — one page trying to
 * be both is one nobody finishes.
 */
export default function TermsPage() {
  return (
    <LegalPage title="Terms of use" updated={TERMS_UPDATED}>
      <Section title="What LobbyHub is">
        <p>
          LobbyHub is a directory and monitor for game servers. We collect what public servers
          report about themselves every few minutes — by querying them directly, or by reading the
          platform listings they publish to — and publish that: player counts, uptime, version, map
          and location. We do not run, host, moderate or sell any of the servers listed here.
        </p>
        <p>
          Using the site means accepting these terms. If you do not accept them, do not use the
          site. What we store about you, and what you can make us delete, is in the{' '}
          <Link href="/privacy" prefetch={false} className="text-brand hover:underline">
            privacy notice
          </Link>
          .
        </p>
      </Section>

      <Section title="Accounts">
        <p>
          You can browse everything without an account. Signing in is needed only to keep favorites
          and to manage servers you added.
        </p>
        <p>
          There are no passwords. You sign in with a one-time code sent to your email address, or
          through Steam, Discord or Google. Keep access to whichever you use: whoever holds it can
          sign in as you. Ask us at <Mail /> and we will delete your account and everything attached
          to it.
        </p>
      </Section>

      <Section title="Adding a server">
        <p>
          Anyone may submit a server address. By submitting one you confirm that you run it or have
          the operator’s permission to list it, and that its address is meant to be public.
        </p>
        <p>
          We verify a submission by querying the address ourselves. What we publish about a server
          is measured from then on, not taken from the form — you cannot type in a player count. The
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
          of the rules above. We do not owe you a listing. If we remove yours and you think we were
          wrong, write to us and a person will look at it.
        </p>
      </Section>

      <Section title="Voting">
        <p>
          You can vote for a server once per day. To enforce that we store a hash of your IP address
          — the hash only, never the address — together with the day. A nickname is optional; if you
          give one it is shown to that server’s operator so they can hand out whatever reward they
          promised. That reward is between you and them; we have no part in it.
        </p>
        <p>
          Do not attempt to inflate a vote count, through scripts, proxies, paid votes or anything
          else. We remove votes we believe are fake, and the listings that bought them.
        </p>
      </Section>

      <Section title="Advertising">
        <p>
          Parts of the site may carry advertising, and a promoted listing may appear above the
          ranking. Anything paid for is labelled as such — a paid position never disguises itself as
          a measured one, and no amount of money changes a player count, an uptime figure or a
          server&rsquo;s rank.
        </p>
        <p>
          Ads are not an endorsement, and what an advertiser sells is between you and them. If ads
          on this site ever store anything on your device, you will be asked first and refusing will
          be as easy as agreeing — see the privacy notice.
        </p>
      </Section>

      <Section title="What our numbers promise">
        <p>
          Nothing. They are readings taken at intervals, over a network that fails sometimes, and
          some of them reach us through a platform&rsquo;s listing rather than from the server
          directly — which can lag it by minutes. A server can be full a second after we saw it
          empty, and a server we could not reach may have been fine for everyone else. Uptime is the
          share of those readings a server was up for — our view of it, not a fact about it.
        </p>
        <p>
          The site is free and provided as is. We do not guarantee that it is available, complete or
          correct, and we are not liable for losses caused by relying on it. Nothing here limits
          liability that cannot be limited by law — for death or personal injury caused by
          negligence, for fraud, or under the mandatory consumer law of the country you live in.
        </p>
      </Section>

      <Section title="Other people’s servers">
        <p>
          A listing is not an endorsement. What happens on a server — its rules, its purchases, its
          moderation, what it does with the data you give it — is the operator’s responsibility, and
          any dispute is between you and them. Connecting to a listed server is your decision.
        </p>
      </Section>

      <Section title="Our content">
        <p>
          The site, its text and its design are ours. Game and server names, logos and cover art
          belong to their owners and appear here to identify what is being listed. If you own
          something published here and want it taken down, write to us and we will.
        </p>
      </Section>

      <Section title="Which law applies">
        <p>
          These terms are governed by the law of the country the site is operated from. If you use
          the site as a consumer in the EU or the UK, that choice does not take away the protections
          your own country&rsquo;s mandatory law gives you, and you can bring a claim in your own
          courts.
        </p>
      </Section>

      <Section title="Changes">
        <p>
          We may change these terms. The date at the top says when they last changed, and continuing
          to use the site after that means accepting the new version.
        </p>
      </Section>

      <Section title="Contact">
        <p>
          Questions, takedowns and deletion requests: <Mail />.
        </p>
      </Section>
    </LegalPage>
  )
}
