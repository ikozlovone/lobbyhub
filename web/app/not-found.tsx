import type { Metadata } from 'next'
import Link from 'next/link'
import { Icon } from '@/components/icons'

export const metadata: Metadata = {
  title: 'Page not found',
}

/**
 * The 404, for both kinds of miss: a URL that matches no route, and the six
 * pages that call notFound() when the catalog has no such game or server.
 *
 * Deliberately static — no data, no API call. This is the page somebody lands
 * on when something is already wrong, and a 404 that needs the catalog to be up
 * in order to render is a 404 that turns an outage into a blank screen. That is
 * also why the recovery here is a plain GET form rather than the suggest box in
 * the header: it works with JavaScript off and with the API down, because the
 * browser does the work.
 *
 * The delisting note is the honest first guess. On a catalog like this most
 * genuine 404s are not typos — they are links to servers that stopped answering
 * and were removed, and saying so is more use than "check the address".
 */
export default function NotFound() {
  return (
    <div className="mx-auto flex max-w-2xl flex-col items-center py-12 text-center sm:py-20">
      <p className="font-display text-6xl font-black tracking-tight text-brand sm:text-7xl">404</p>

      <h1 className="font-display mt-4 text-2xl font-black tracking-tight uppercase sm:text-3xl">
        This page is not here
      </h1>

      <p className="mt-3 text-muted">
        The address may be mistyped — or the server it pointed to was removed from the catalog
        after it stopped answering our checks. Its game page will list what is still up.
      </p>

      <form
        role="search"
        action="/search"
        method="get"
        className="mt-8 flex w-full flex-col gap-2 sm:flex-row"
      >
        <div className="relative min-w-0 flex-1">
          <label htmlFor="not-found-search" className="sr-only">
            Search servers by name, game or address
          </label>
          <span aria-hidden className="absolute top-1/2 left-4 -translate-y-1/2 text-subtle">
            <Icon.search className="size-5" />
          </span>
          <input
            id="not-found-search"
            name="q"
            type="search"
            autoComplete="off"
            placeholder="Search servers by name, game or address"
            className="w-full rounded-xl border border-line bg-surface py-3.5 pr-4 pl-11 text-left outline-none transition-colors placeholder:text-subtle focus:border-brand"
          />
        </div>
        <button
          type="submit"
          className="shrink-0 cursor-pointer rounded-xl bg-brand px-6 py-3.5 font-medium text-white transition-colors hover:bg-brand-strong"
        >
          Search
        </button>
      </form>

      {/* Named destinations, not "go back": the browser already has a back
          button, and what somebody who followed a dead link actually needs is
          the listing that replaced it. */}
      <nav aria-label="Recover" className="mt-6 flex flex-wrap justify-center gap-2">
        <Link
          href="/games"
          prefetch={false}
          className="cursor-pointer rounded-xl border border-brand/50 bg-brand/10 px-5 py-2.5 text-sm font-medium text-brand transition-colors hover:bg-brand/20"
        >
          Browse servers by game
        </Link>
        <Link
          href="/search"
          prefetch={false}
          className="cursor-pointer rounded-xl border border-line-strong px-5 py-2.5 text-sm font-medium text-fg transition-colors hover:bg-surface-2"
        >
          All servers
        </Link>
        <Link
          href="/add-server"
          prefetch={false}
          className="cursor-pointer rounded-xl border border-line-strong px-5 py-2.5 text-sm font-medium text-fg transition-colors hover:bg-surface-2"
        >
          Add your server
        </Link>
      </nav>
    </div>
  )
}
