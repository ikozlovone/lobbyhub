import Link from 'next/link'
import { Icon } from '../icons'
import { HOME_COPY } from './copy'

/**
 * The other half of the audience: the people with a server rather than the
 * people looking for one.
 *
 * The spec also asks for a "Claim an Existing Server" link beside this, on the
 * condition that ownership verification exists. It does not — there is no claim
 * route, no page and no endpoint (the API's `votes/claim` is an owner marking a
 * voter as rewarded, which is a different thing) — so the link is deliberately
 * absent rather than pointing at a 404.
 */
export function ServerOwnerCta() {
  return (
    <section
      aria-labelledby="section-owners"
      className="rounded-2xl border border-brand/30 bg-brand/5 p-6 sm:p-8"
    >
      <div className="flex flex-col items-start justify-between gap-5 lg:flex-row lg:items-center">
        <div className="min-w-0 max-w-2xl">
          <h2
            id="section-owners"
            className="font-display text-xl font-black tracking-tight uppercase"
          >
            {HOME_COPY.owners.title}
          </h2>
          <p className="mt-2 text-sm text-muted">{HOME_COPY.owners.description}</p>
        </div>

        <Link
          href="/add-server"
          prefetch={false}
          className="flex shrink-0 cursor-pointer items-center gap-2 rounded-xl bg-brand px-5 py-3 font-medium text-white transition-colors hover:bg-brand-strong"
        >
          <Icon.plus />
          {HOME_COPY.owners.action}
        </Link>
      </div>
    </section>
  )
}
