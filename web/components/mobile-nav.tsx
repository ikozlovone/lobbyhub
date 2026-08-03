'use client'

import { usePathname } from 'next/navigation'
import { useEffect, useRef, useState } from 'react'
import { Icon } from './icons'
import { SearchBox } from './search-box'

/**
 * The rail, as a drawer, for screens too narrow to keep it open.
 *
 * The header used to spend its whole middle on the search box below lg, which
 * on a 360px screen left the wordmark and two buttons fighting over what was
 * left — and still gave a phone no way to reach the games list at all, since
 * the rail is desktop-only. A button costs one square instead.
 *
 * The rail itself is passed in as children rather than imported: Sidebar is an
 * async server component that reads the catalog, and it renders on the server
 * either way. This file only decides when it is on screen.
 *
 * Built on <dialog> for the third time in this codebase, for the same reasons:
 * focus trapping, Escape, the backdrop and making the page behind it inert are
 * browser behaviour, and every hand-rolled drawer reimplements them badly.
 */
export function MobileNav({ apiUrl, children }: { apiUrl: string; children: React.ReactNode }) {
  const dialog = useRef<HTMLDialogElement>(null)
  const [open, setOpen] = useState(false)

  const pathname = usePathname()

  useEffect(() => {
    const element = dialog.current

    if (!element) return

    if (open && !element.open) element.showModal()
    if (!open && element.open) element.close()
  }, [open])

  // Escape and the backdrop both close a <dialog> without telling React.
  useEffect(() => {
    const element = dialog.current

    if (!element) return

    const closed = () => setOpen(false)

    element.addEventListener('close', closed)

    return () => element.removeEventListener('close', closed)
  }, [])

  /*
   * Every link in here navigates, and a drawer still covering the page it just
   * opened is the oldest bug in off-canvas menus. Keyed on the path rather than
   * on the click so it also closes for the back button.
   *
   * Adjusted during render rather than in an effect: this is React's own answer
   * for "reset state when something changes", and it closes the drawer in the
   * same pass instead of drawing it once more over the new page first.
   *
   * Not useSearchParams, which would also catch query-only navigations this
   * drawer does not have: reading it in the root layout opts every statically
   * rendered page out of being static. The click handler below covers the gap —
   * a link to the page you are already on.
   */
  const [drawnAt, setDrawnAt] = useState(pathname)

  if (drawnAt !== pathname) {
    setDrawnAt(pathname)
    setOpen(false)
  }

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-label="Open menu"
        aria-expanded={open}
        aria-controls="mobile-nav"
        className="flex shrink-0 cursor-pointer items-center justify-center rounded-lg border border-line p-1.5 text-muted transition-colors hover:bg-surface-2 hover:text-fg lg:hidden"
      >
        <Icon.menu className="size-5" />
      </button>

      <dialog
        ref={dialog}
        id="mobile-nav"
        aria-label="Catalog"
        onClick={(event) => event.target === dialog.current && setOpen(false)}
        /*
         * Pinned to the left edge at full height rather than centred: `m-0` and
         * `mr-auto` beat the user-agent stylesheet's auto margins, which is what
         * would otherwise float it in the middle of the screen like a modal.
         *
         * Capped at 20rem *and* at the viewport, so it is a drawer on a phone
         * and still a drawer on a 320px one.
         */
        className="m-0 mr-auto h-dvh w-[min(20rem,85vw)] max-w-none border-r border-line bg-surface p-0 text-fg backdrop:bg-black/70 backdrop:backdrop-blur-sm lg:hidden"
      >
        <div className="flex h-full flex-col">
          <div className="flex items-center justify-between gap-3 border-b border-line p-4">
            <p className="font-display text-lg font-black tracking-tight">
              LOBBY<span className="text-brand">HUB</span>
            </p>
            <button
              type="button"
              onClick={() => setOpen(false)}
              aria-label="Close menu"
              className="cursor-pointer rounded-lg p-1.5 text-muted transition-colors hover:bg-surface-2 hover:text-fg"
            >
              <Icon.close className="size-5" />
            </button>
          </div>

          {/* Search comes with it. Taking the box out of the header would
              otherwise leave a phone with no way to search from any page but
              the home one, which is a worse trade than the space it cost. */}
          <div className="border-b border-line p-4">
            <SearchBox apiUrl={apiUrl} />
          </div>

          {/* Delegated, because the links are server-rendered children this
              component never sees. Covers the one case the path effect cannot:
              tapping a link to the page already open. */}
          <div
            className="min-h-0 flex-1 overflow-y-auto p-4"
            onClick={(event) => {
              if ((event.target as HTMLElement).closest('a')) setOpen(false)
            }}
          >
            {children}
          </div>
        </div>
      </dialog>
    </>
  )
}
