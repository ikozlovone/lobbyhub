'use client'

import { useCallback, useEffect, useRef, useState } from 'react'

/**
 * The thin green line across the top of the window during a page change.
 *
 * This exists because navigation here is not always instant. The shell of a
 * route is prefetched, but the listing behind it is a request-time read — the
 * staleTimes note in next.config.ts is the whole reason — so between the click
 * and the first rows there is a gap the visitor is currently told nothing
 * about. On a phone, on a slow connection, that gap is long enough that people
 * press the link a second time.
 *
 * Deliberately not `nextjs-toploader`, which is the obvious thing to reach for.
 * Read its source: `start()` is called from one place, a document click
 * handler, and `pushState`/`replaceState` are patched to call `done()`. That is
 * the right shape for the *end* of a navigation — Next commits the URL in an
 * effect once the new router state lands, so a history write really is the
 * finish line — but it means a programmatic `router.push()` never starts the
 * bar at all. It has no click to hang off, and the only signal it does emit is
 * the one the library reads as "finished".
 *
 * So the start signal is taken from intent instead of from history:
 *
 *   - a click on an internal link, which is every navigation a visitor makes;
 *   - `popstate`, for back and forward;
 *   - `startNavigationProgress()`, for the code paths that navigate without a
 *     click. There is no framework hook for this. `useLinkStatus` only reports
 *     for descendants of the `Link` that was clicked, and `useRouter()` in this
 *     version hands back a fresh memoised object per call site rather than the
 *     shared instance, so there is nothing to wrap once and have it hold.
 *
 * The finish signal is the history write Next makes when a navigation commits.
 * Deliberately not a router hook: `usePathname()` here is URL data read in a
 * client component in the root layout, and under Cache Components that blocks
 * prerendering for every route unless it is wrapped in `<Suspense>` — the build
 * refuses it outright. Reading `useSearchParams()` would be the same mistake
 * the drawer in mobile-nav.tsx already documents. So this component reads
 * nothing from the router at all: it is driven entirely by DOM events, stays in
 * the prerendered shell, and costs the routes around it nothing.
 */

/*
 * A navigation has no measurable progress: there is no total to divide by, only
 * a request that has not come back. So the bar is a lie told at a decreasing
 * rate — it moves quickly at first, then slower, and stops short of the end
 * until the page actually arrives. Landing on 100% is the only honest frame in
 * it, and it is the one that coincides with the new page being there.
 */
const CEILING = 90
const TRICKLE_MS = 180

/* Long enough to read as travel, short enough not to hold up a fast route. */
const SWEEP_MS = 200
const FADE_MS = 250

/*
 * Nothing should be able to leave a bar on the screen. If a navigation is
 * abandoned, refused by a route guard, or simply never commits, this clears it.
 * Well past any real page load, so it never truncates a slow one.
 */
const FAILSAFE_MS = 10_000

/*
 * How the code that navigates without a click says so.
 *
 * An event rather than an exported setter: this is dispatched from modules that
 * have no reason to know the bar exists as a component, and a window event
 * costs them one line and no import graph.
 */
const START_EVENT = 'lobbyhub:navigation-start'

/** Show the bar for a navigation that no click will announce — `router.push`. */
export function startNavigationProgress() {
  if (typeof window !== 'undefined') window.dispatchEvent(new Event(START_EVENT))
}

export function NavigationProgress() {
  const [width, setWidth] = useState(0)
  const [visible, setVisible] = useState(false)

  /*
   * Whether a navigation is in flight is a ref, not state. Two links pressed in
   * quick succession must not restart the bar — the second press is the same
   * journey continuing, and a bar that snapped back to zero would read as
   * progress lost. The ref lets the second start be ignored without the check
   * itself causing a render.
   */
  const running = useRef(false)
  const timers = useRef<ReturnType<typeof setTimeout>[]>([])
  const trickle = useRef<ReturnType<typeof setInterval> | null>(null)

  const stopTimers = useCallback(() => {
    if (trickle.current) clearInterval(trickle.current)
    trickle.current = null
    timers.current.forEach(clearTimeout)
    timers.current = []
  }, [])

  const done = useCallback(() => {
    if (!running.current) return
    running.current = false
    stopTimers()

    setWidth(100)

    // Fade only after the sweep to 100% has been seen. Resetting the width is a
    // third step because it must happen while the bar is already invisible —
    // done any earlier it would animate back down the screen.
    timers.current.push(
      setTimeout(() => setVisible(false), SWEEP_MS),
      setTimeout(() => setWidth(0), SWEEP_MS + FADE_MS),
    )
  }, [stopTimers])

  const start = useCallback(() => {
    if (running.current) return
    running.current = true
    stopTimers()

    // Far enough in to be visible on the frame it appears. A bar that starts at
    // zero width is not yet a bar, and the point is to answer the click.
    setVisible(true)
    setWidth(8)

    // Each step covers a fixed share of what is left, which is what makes it
    // slow down on its own without ever quite arriving.
    trickle.current = setInterval(() => {
      setWidth((current) => (current >= CEILING ? current : current + (CEILING - current) * 0.12))
    }, TRICKLE_MS)

    timers.current.push(setTimeout(done, FAILSAFE_MS))
  }, [done, stopTimers])

  /*
   * The finish line, taken from history rather than from React.
   *
   * Next commits a navigation by writing the new URL to history in an effect,
   * once the new router state has landed — so the write is the frame the new
   * page is on screen, and it happens on every commit, including one that ends
   * on the path it started from.
   *
   * That last part is why watching the path instead would not do, and it is not
   * a hypothetical: press three links quickly enough and the last of them can
   * land back on the page the first was left from. The path ends as it started,
   * nothing downstream of it changes, and the bar creeps on until the failsafe
   * cuts it. Measured, not reasoned about — an earlier draft of this file sat at
   * 76% for the full two and a half seconds the test watched it.
   *
   * The filter chips in server-browser.tsx write history directly too, and this
   * treats those as a finish as well. That is deliberate. They only fire while
   * somebody is working the filters, which is not a moment there is a bar on
   * screen, and finishing one early is a far smaller fault than leaving one up.
   */
  useEffect(() => {
    const wrap =
      (write: History['pushState']): History['pushState'] =>
      function (this: History, ...args) {
        const result = write.apply(this, args)

        // Off the current stack before touching state. Next makes this write
        // from inside a `useInsertionEffect`, and React refuses updates
        // scheduled during one — finishing the bar synchronously here logs
        // "useInsertionEffect must not schedule updates" on every navigation. A
        // microtask runs once that commit has unwound, which is soon enough to
        // be the same frame and late enough to be allowed.
        queueMicrotask(done)

        return result
      }

    const { pushState, replaceState } = window.history
    window.history.pushState = wrap(pushState)
    window.history.replaceState = wrap(replaceState)

    return () => {
      window.history.pushState = pushState
      window.history.replaceState = replaceState
    }
  }, [done])

  useEffect(() => {
    const onClick = (event: MouseEvent) => {
      // Anything the page has already handled, or that the browser will not
      // treat as a plain navigation: a new tab, a download, a middle click.
      if (event.defaultPrevented || event.button !== 0) return
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return

      const anchor = (event.target as Element | null)?.closest?.('a')
      if (!anchor || !anchor.href || anchor.hasAttribute('download')) return
      if (anchor.target && anchor.target !== '_self') return

      const next = new URL(anchor.href, window.location.href)
      const here = new URL(window.location.href)

      // Another origin leaves the app entirely; the browser draws its own
      // progress for that and this page is about to be gone.
      if (next.origin !== here.origin) return

      // A jump to an anchor on this page, or a link to the page already shown.
      // Neither commits a route, so neither would ever finish.
      if (next.pathname === here.pathname && next.search === here.search) return

      start()
    }

    const onStart = () => start()

    /*
     * A link that leaves the app for good is left running on purpose — the page
     * is about to be replaced and the browser draws its own progress. But
     * `pagehide` is not always the end: come back to a bfcached page and the
     * DOM is restored exactly as it was left, bar included, with no navigation
     * left to finish it. So it is cleared on the way out.
     */
    const onHide = () => done()

    /*
     * Capture. The favorite stars are siblings of the row link rather than
     * children of it — server-card.tsx is explicit that a link never contains
     * another control — so nothing here cancels a click on its way up, and
     * running first is what puts the bar on screen in the same frame as the
     * press.
     */
    document.addEventListener('click', onClick, true)
    window.addEventListener('popstate', onStart)
    window.addEventListener('pagehide', onHide)
    window.addEventListener(START_EVENT, onStart)

    return () => {
      document.removeEventListener('click', onClick, true)
      window.removeEventListener('popstate', onStart)
      window.removeEventListener('pagehide', onHide)
      window.removeEventListener(START_EVENT, onStart)
    }
  }, [start, done])

  useEffect(() => stopTimers, [stopTimers])

  return (
    /*
     * Fixed and never unmounted, so it takes no space and the markup the server
     * sends is the markup the client hydrates — the bar is present and
     * transparent on a cold load, not conditionally rendered. Above the sticky
     * header and the dropdowns, below the toast layer. Nothing here can be
     * pressed, and it sits over the header, so pointer events go through it.
     */
    <div aria-hidden className="pointer-events-none fixed inset-x-0 top-0 z-40 h-[3px]">
      <div
        className="h-full bg-brand"
        style={{
          width: `${width}%`,
          opacity: visible ? 1 : 0,
          boxShadow: '0 0 8px color-mix(in oklab, var(--color-brand) 70%, transparent)',
          // Opacity is only ever animated on the way out. Fading in would put
          // the confirmation of a click a quarter of a second after the click,
          // and the width reset that follows the fade must not animate at all.
          transition: visible
            ? `width ${SWEEP_MS}ms ease-out`
            : `opacity ${FADE_MS}ms ease-out, width 0s`,
        }}
      />
    </div>
  )
}
