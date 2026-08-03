import Link from 'next/link'
import { Icon } from '../icons'
import { HOME_COPY } from './copy'

/**
 * The first screen: what this is, the search, and the two things to do next.
 *
 * Entirely server-rendered, search included. The form is a plain GET to
 * /search, so it works before hydration and with JavaScript off altogether —
 * which no amount of client-side state would give us, and which is why the
 * search here is not the debounced suggest box from the header. That one is
 * still in the header, one tab stop away, for people who want suggestions.
 */
export function HeroSection() {
  return (
    <section className="rounded-2xl border border-line bg-surface/60 p-6 sm:p-8 lg:p-10">
      <div className="mx-auto max-w-3xl text-center">
        <h1 className="font-display text-3xl leading-tight font-black tracking-tight uppercase sm:text-4xl lg:text-5xl">
          Find the Best <span className="text-brand">Game Servers</span>
        </h1>

        <p className="mx-auto mt-4 max-w-2xl text-muted">{HOME_COPY.hero.subtitle}</p>

        {/* role="search" on the form, a real label on the input: the landmark is
            what lets somebody jump straight here, and a placeholder is not a
            label once there is text in the field. */}
        <form
          role="search"
          action="/search"
          method="get"
          className="mt-7 flex flex-col gap-2 sm:flex-row"
        >
          <div className="relative min-w-0 flex-1">
            <label htmlFor="home-search" className="sr-only">
              {HOME_COPY.hero.searchLabel}
            </label>
            <span aria-hidden className="absolute top-1/2 left-4 -translate-y-1/2 text-subtle">
              <Icon.search className="size-5" />
            </span>
            <input
              id="home-search"
              name="q"
              type="search"
              autoComplete="off"
              placeholder={HOME_COPY.hero.searchPlaceholder}
              className="w-full rounded-xl border border-line bg-bg py-3.5 pr-4 pl-11 text-left outline-none transition-colors placeholder:text-subtle focus:border-brand"
            />
          </div>

          <button
            type="submit"
            className="shrink-0 cursor-pointer rounded-xl bg-brand px-6 py-3.5 font-medium text-white transition-colors hover:bg-brand-strong"
          >
            Search
          </button>
        </form>

        <div className="mt-5 flex flex-col justify-center gap-2 sm:flex-row">
          <Link
            href="/games"
            prefetch={false}
            className="cursor-pointer rounded-xl border border-brand/50 bg-brand/10 px-5 py-2.5 text-sm font-medium text-brand transition-colors hover:bg-brand/20"
          >
            Browse servers
          </Link>
          <Link
            href="/add-server"
            prefetch={false}
            className="cursor-pointer rounded-xl border border-line-strong px-5 py-2.5 text-sm font-medium text-fg transition-colors hover:bg-surface-2"
          >
            Add your server
          </Link>
        </div>
      </div>
    </section>
  )
}
