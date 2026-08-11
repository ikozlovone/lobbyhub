import type { Metadata } from 'next'
import { GoogleAnalytics } from '@next/third-parties/google'
import { Inter, JetBrains_Mono, Orbitron } from 'next/font/google'
import Link from 'next/link'
import { Suspense } from 'react'
import { AuthProvider } from '@/components/auth/auth-provider'
import { UserMenu } from '@/components/auth/user-menu'
import { ConsentGate, ConsentProvider, ConsentSettingsButton } from '@/components/consent-provider'
import { FavoritesProvider } from '@/components/favorites-provider'
import { Icon } from '@/components/icons'
import { MobileNav } from '@/components/mobile-nav'
import { SearchBox } from '@/components/search-box'
import { Sidebar } from '@/components/sidebar'
import { ToastProvider } from '@/components/toast'
import { getAuthProviders } from '@/lib/data'
import './globals.css'

/*
 * Three roles, not three decorations:
 *   Orbitron — wordmark and page titles only. It is a display face; body copy
 *              set in it is unreadable at listing density.
 *   Inter    — everything a person actually reads.
 *   Mono     — numbers, ports and addresses, so digits stay aligned in tables.
 */
const orbitron = Orbitron({ subsets: ['latin'], weight: ['700', '900'], variable: '--font-orbitron' })
const inter = Inter({ subsets: ['latin'], variable: '--font-inter' })
const mono = JetBrains_Mono({ subsets: ['latin'], weight: ['400', '500'], variable: '--font-mono-code' })

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'
const GA_ID = process.env.NEXT_PUBLIC_GA_ID

export const metadata: Metadata = {
  metadataBase: new URL(process.env.NEXT_PUBLIC_SITE_URL ?? 'http://localhost:3000'),
  title: {
    default: 'LobbyHub — game server monitoring and top lists',
    template: '%s | LobbyHub',
  },
  description:
    'Live player counts, uptime history and rankings for Minecraft, Rust, FiveM and more.',
}

export default async function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  // Which sign-in buttons exist is deployment configuration, so it is read on
  // the server and shipped with the shell instead of fetched on every visit.
  //
  // Caught out here, not inside the cached read: an API that is down should cost
  // this render its provider buttons, not cache the absence of them for an hour.
  const providers = await getAuthProviders().catch(() => [])

  return (
    <html lang="en" className={`${orbitron.variable} ${inter.variable} ${mono.variable} h-full`}>
      <body className="flex min-h-full flex-col">
        {/* Outermost: the sign-in dialog inside AuthProvider raises toasts too. */}
        <ToastProvider>
          {/* Above everything that could ever want permission. Nothing needing
              consent may mount outside it, and the answer starts at no. */}
          <ConsentProvider>
          <AuthProvider apiUrl={API_URL} providers={providers}>
            {/* Inside AuthProvider because it only has anything to load once
                somebody is signed in, and pressing a star while signed out is
                what opens that provider's dialog. */}
            <FavoritesProvider apiUrl={API_URL}>
            <header className="sticky top-0 z-20 border-b border-line bg-bg/90 backdrop-blur">
              <div className="mx-auto flex h-14 w-full max-w-[100rem] items-center gap-3 px-4">
                {/* Below lg only, where the rail beside the page is hidden and
                    this is the whole of the catalog navigation. The rail is
                    rendered on the server either way and handed over as
                    children — see MobileNav. */}
                {/* The boundary goes above the client component, not inside its
                    children: a Suspense passed *through* a client component as
                    part of children does not shield the route from the catalog
                    read inside it, and the build says so. The fallback holds the
                    button's square so the header does not jump. */}
                <Suspense fallback={<div className="size-8 shrink-0 lg:hidden" />}>
                  <MobileNav apiUrl={API_URL}>
                    <Sidebar />
                  </MobileNav>
                </Suspense>

                <Link
                  href="/"
                  className="font-display shrink-0 text-lg font-black tracking-tight transition-colors hover:text-brand"
                >
                  LOBBY<span className="text-brand">HUB</span>
                </Link>

                {/* Hidden below lg, where it used to take the whole middle of a
                    360px header and leave nothing for the wordmark. The same box
                    is the first thing inside the drawer. min-w-0 above lg, or it
                    refuses to shrink past its own content. */}
                <div className="hidden min-w-0 flex-1 justify-center lg:flex">
                  <SearchBox apiUrl={API_URL} />
                </div>

                {/* Holds the row open once the search box stops doing it, so the
                    account button stays at the right edge instead of sliding
                    into the middle. */}
                <div className="flex-1 lg:hidden" />

                {/* The one thing a server owner comes here to do, reachable from
                    every page rather than only from the home grid. */}
                <Link
                  href="/add-server"
                  prefetch={false}
                  className="flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg bg-brand px-3 py-1.5 text-sm font-medium text-white transition-colors hover:bg-brand-strong"
                >
                  <Icon.plus />
                  <span className="hidden sm:inline">Add server</span>
                </Link>

                <UserMenu />
              </div>
            </header>

            <div className="mx-auto flex w-full max-w-[100rem] flex-1 gap-6 px-4 py-6">
              {/* Below lg the games list would push the page down before any of its
                  content; it is reachable from the home page there instead. */}
              <aside className="hidden w-56 shrink-0 lg:block">
                <div className="scrollbar-slim sticky top-20 max-h-[calc(100dvh-6rem)] overflow-y-auto pr-1">
                  <Suspense fallback={<div className="h-64 animate-pulse rounded bg-surface" />}>
                    <Sidebar />
                  </Suspense>
                </div>
              </aside>

              <main className="min-w-0 flex-1">{children}</main>
            </div>

            <footer className="border-t border-line py-8 text-sm text-subtle">
              <div className="mx-auto flex w-full max-w-[100rem] flex-col gap-6 px-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="max-w-md space-y-2">
                  <p className="font-display text-base font-black tracking-tight text-fg">
                    LOBBY<span className="text-brand">HUB</span>
                  </p>
                  <p>
                    LobbyHub helps players discover multiplayer game servers and gaming communities.
                  </p>
                  <p>
                    Player counts refresh every few minutes. Uptime is measured, not reported by
                    owners.
                  </p>
                </div>

                {/* Two lists, because they answer different questions — where do
                    I go, and who is behind this. Every entry is a page that
                    exists: there is no About or Contact to link to, and a footer
                    link to a 404 is worse than a missing one. */}
                <div className="flex flex-wrap gap-x-12 gap-y-6">
                  <nav aria-label="Browse">
                    <p className="font-display mb-2 text-[11px] font-bold tracking-widest text-fg uppercase">
                      Browse
                    </p>
                    <ul className="space-y-1.5">
                      <li>
                        <Link href="/games" prefetch={false} className="transition-colors hover:text-fg">
                          Games
                        </Link>
                      </li>
                      <li>
                        <Link href="/search" prefetch={false} className="transition-colors hover:text-fg">
                          Servers
                        </Link>
                      </li>
                      <li>
                        <Link href="/add-server" prefetch={false} className="transition-colors hover:text-fg">
                          Add server
                        </Link>
                      </li>
                      <li>
                        <Link href="/contact" prefetch={false} className="transition-colors hover:text-fg">
                          Contact
                        </Link>
                      </li>
                    </ul>
                  </nav>

                  <nav aria-label="Legal">
                    <p className="font-display mb-2 text-[11px] font-bold tracking-widest text-fg uppercase">
                      Legal
                    </p>
                    <ul className="space-y-1.5">
                      <li>
                        <Link href="/terms" prefetch={false} className="transition-colors hover:text-fg">
                          Terms of use
                        </Link>
                      </li>
                      <li>
                        <Link href="/privacy" prefetch={false} className="transition-colors hover:text-fg">
                          Privacy
                        </Link>
                      </li>
                      <li>
                        {/* Withdrawing consent has to be reachable from every
                            page, not only the ones with a rail. */}
                        <ConsentSettingsButton className="cursor-pointer transition-colors hover:text-fg" />
                      </li>
                    </ul>
                  </nav>
                </div>
              </div>

              {/* The fine print, in a row of its own: copyright, attribution
                  and the independence disclaimer belong together, and putting
                  them under the columns above rather than inside one of them
                  keeps the columns readable at their intended widths. */}
              <div className="mx-auto mt-8 w-full max-w-[100rem] border-t border-line px-4 pt-6">
                {/* Hardcoded year rather than new Date().getFullYear(): the
                    layout prerenders, and reading the current time inside a
                    prerender is a non-deterministic value this Next build
                    refuses. A copyright year is the year of publication or
                    the last substantial revision anyway, not today — updated
                    by hand once a year, which is what any real copyright
                    notice already needs. */}
                <p className="text-xs leading-relaxed">
                  © 2026 LobbyHub. All rights reserved. Content may be reused
                  only with a working link back to the source. LobbyHub is an
                  independent project — not affiliated with, endorsed by, or
                  sponsored by any game publisher or developer. All trademarks
                  and registered trademarks are the property of their
                  respective owners.
                </p>
              </div>
            </footer>
            </FavoritesProvider>
          </AuthProvider>

          {/* Google Analytics is loaded only after the visitor has allowed
              'analytics' — ConsentGate renders nothing until then, so no
              request is made to googletagmanager.com and no cookie is set.
              Withdrawing consent from the footer unmounts the component and
              no further events are sent.

              Inside ConsentProvider on purpose: ConsentGate reads the context
              and throws at prerender time if it is mounted outside. */}
          {GA_ID && (
            <ConsentGate category="analytics">
              <GoogleAnalytics gaId={GA_ID} />
            </ConsentGate>
          )}
          </ConsentProvider>
        </ToastProvider>
      </body>
    </html>
  )
}
