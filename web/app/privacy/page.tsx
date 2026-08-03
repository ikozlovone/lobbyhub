import type { Metadata } from 'next'
import Link from 'next/link'
import { ConsentSettingsButton } from '@/components/consent-provider'
import { LegalPage, Mail, Section } from '@/components/legal-page'
import { CONTROLLER, PRIVACY_UPDATED } from '@/lib/legal'
import { canonical } from '@/lib/seo'

export const metadata: Metadata = {
  title: 'Privacy',
  description:
    'What LobbyHub stores about you, why, for how long, and how to get it deleted. Written for the GDPR.',
  ...canonical('/privacy'),
}

/**
 * The Art. 13 notice.
 *
 * Every retention figure here is one the code actually enforces — the ten
 * minutes is `auth.codes.ttl`, the fourteen days is `stats:rollup --prune-days`.
 * A notice that promises a deletion nobody implemented is worse than none: it
 * is a written admission of a breach. Anything changed on either side has to be
 * changed on both.
 */
export default function PrivacyPage() {
  return (
    <LegalPage title="Privacy" updated={PRIVACY_UPDATED}>
      <Section title="Who is responsible">
        <p>
          {CONTROLLER.name}, {CONTROLLER.address}, is the controller of the personal data described
          here, and can be reached at <Mail />. There is no data protection officer — the site is
          not large enough to require one.
        </p>
        <p>
          This notice covers lobbyhub.gg and its API. It does not cover the game servers listed on
          it: those are run by other people, and what happens once you connect to one is between you
          and them.
        </p>
      </Section>

      <Section title="What we hold, and why we are allowed to">
        <p>Six things, and nothing else.</p>

        <Item
          title="Your account"
          basis="Art. 6(1)(b) — performing the service you asked for"
          keeps="Until you delete it"
        >
          If you sign in by email, your address. If you sign in through Steam, Discord or Google,
          the id, display name and avatar that provider hands back — not your password, and not
          your friends list. Either way, the time you last signed in. You can browse the entire site
          without any of it.
        </Item>

        <Item
          title="Sign-in codes"
          basis="Art. 6(1)(b) — performing the service you asked for"
          keeps="10 minutes"
        >
          A hash of the six-digit code, against your address. It stops working ten minutes after it
          is issued.
        </Item>

        <Item
          title="Servers you add, and servers you favourite"
          basis="Art. 6(1)(b) — performing the service you asked for"
          keeps="While the listing or the favourite exists"
        >
          The address you submitted and the fact that it was you who submitted it. Favourites are a
          list of server ids against your account.
        </Item>

        <Item
          title="Votes"
          basis="Art. 6(1)(f) — our legitimate interest in a ranking that means something"
          keeps="While the listing exists"
        >
          A vote stores the day, the server, an optional nickname, and a hash of your IP address —
          the hash, never the address itself, and it exists only so the same person cannot vote
          twice in a day. If you give a nickname it is shown to that server&rsquo;s operator, which
          is the entire point of giving one. You can vote without one.
        </Item>

        <Item
          title="Web server logs"
          basis="Art. 6(1)(f) — our legitimate interest in keeping the site up and unabused"
          keeps="At most 14 days"
        >
          Your IP address, the page requested, the time, and what your browser says it is. This is
          the ordinary log every web server writes; we use it to find faults and to deal with abuse,
          and nothing in it is used to build a picture of you.
        </Item>

        <Item
          title="Analytics and advertising"
          basis="Art. 6(1)(a) — your consent, and only if you give it"
          keeps="Only while consent stands"
        >
          Neither is running on this site today. If either starts, you will be asked first, refusing
          will be exactly as easy as agreeing, and nothing will be stored on your device until you
          answer. You can change or withdraw the answer whenever you like:{' '}
          <ConsentSettingsButton className="cursor-pointer text-brand hover:underline" />.
        </Item>

        <p>
          We do not sell personal data, we do not profile you, and nothing here is decided about you
          automatically in a way that has legal or similarly significant effects.
        </p>
      </Section>

      <Section title="What is stored on your device">
        <p>
          No cookies at all, today. Signing in puts a session token in your browser&rsquo;s local
          storage, and moving through the sign-in screen briefly puts the page you came from in
          session storage. Both are strictly necessary to do what you asked for, so neither needs
          your permission — and both are gone when you sign out or close the tab.
        </p>
        <p>
          Our network provider (Cloudflare) may set a cookie of its own to tell people apart from
          bots. That one is necessary for the site to stay reachable.
        </p>
        <p>
          Fonts are served from our own server. Nothing on this site loads a script, a font or an
          image from a third party who could log your visit.
        </p>
      </Section>

      <Section title="Who else sees it">
        <p>
          The server, and the few services it cannot work without: our host, our network provider
          (Cloudflare), and the mail provider that delivers your sign-in code. Each acts on our
          instructions under a contract, and none of them may use your data for their own purposes.
        </p>
        <p>
          If you sign in through Steam, Discord or Google, that provider learns you signed in to
          LobbyHub. That is inherent to using them, and it is why email sign-in exists as an
          alternative.
        </p>
      </Section>

      <Section title="Where it is held">
        <p>
          On a single server in {CONTROLLER.hosting}. Cloudflare, which sits in front of it, is a US
          company and may process traffic data outside the EU; those transfers rely on the European
          Commission&rsquo;s standard contractual clauses.
        </p>
      </Section>

      <Section title="Your rights">
        <p>
          If you are in the EU or the UK the GDPR gives you the right to ask us for a copy of what
          we hold about you, to correct it, to have it deleted, to restrict or object to what we do
          with it, and to receive it in a portable form. Where we rely on your consent you can
          withdraw it at any time, and doing so does not make what we did before it unlawful.
        </p>
        <p>
          Write to <Mail />. We answer within a month. Deleting your account deletes everything tied
          to it, and we would rather do that than argue with you about it.
        </p>
        <p>
          If we get it wrong you can complain to your national data protection authority — in the EU
          that is the one where you live, work, or where the problem happened. We would appreciate
          the chance to fix it first.
        </p>
      </Section>

      <Section title="Children">
        <p>
          The site is not aimed at children, and we do not knowingly keep accounts for anyone under
          16. Tell us if one exists and it will be removed.
        </p>
      </Section>

      <Section title="Changes">
        <p>
          The date at the top says when this last changed. If a change means we start doing
          something materially different with your data, we will ask before it applies to you rather
          than after.
        </p>
        <p>
          The rules for using the site are in the{' '}
          <Link href="/terms" prefetch={false} className="text-brand hover:underline">
            terms of use
          </Link>
          .
        </p>
      </Section>
    </LegalPage>
  )
}

/**
 * One kind of data, with the two things a reader is actually looking for pulled
 * out of the prose: what lets us hold it, and when it goes away.
 */
function Item({
  title,
  basis,
  keeps,
  children,
}: {
  title: string
  basis: string
  keeps: string
  children: React.ReactNode
}) {
  return (
    <div className="rounded-lg border border-line p-4">
      <h3 className="font-medium text-fg">{title}</h3>
      <p className="mt-1.5">{children}</p>
      <dl className="mt-3 grid gap-x-4 gap-y-1 text-xs text-subtle sm:grid-cols-[auto_1fr]">
        <dt className="font-medium">Legal basis</dt>
        <dd>{basis}</dd>
        <dt className="font-medium">Kept for</dt>
        <dd>{keeps}</dd>
      </dl>
    </div>
  )
}
