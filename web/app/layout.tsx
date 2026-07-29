import type { Metadata } from 'next'
import { Inter, JetBrains_Mono, Orbitron } from 'next/font/google'
import Link from 'next/link'
import { Suspense } from 'react'
import { AuthProvider } from '@/components/auth/auth-provider'
import { UserMenu } from '@/components/auth/user-menu'
import { Icon } from '@/components/icons'
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
          <AuthProvider apiUrl={API_URL} providers={providers}>
            <header className="sticky top-0 z-20 border-b border-line bg-bg/90 backdrop-blur">
              <div className="mx-auto flex h-14 w-full max-w-[100rem] items-center gap-3 px-4">
                <Link
                  href="/"
                  className="font-display shrink-0 text-lg font-black tracking-tight transition-colors hover:text-brand"
                >
                  LOBBY<span className="text-brand">HUB</span>
                </Link>
                <div className="flex flex-1 justify-center">
                  <SearchBox apiUrl={API_URL} />
                </div>

                {/* The one thing a server owner comes here to do, reachable from
                    every page rather than only from the home grid. */}
                <Link
                  href="/add-server"
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
                <div className="sticky top-20 max-h-[calc(100dvh-6rem)] overflow-y-auto pr-1">
                  <Suspense fallback={<div className="h-64 animate-pulse rounded bg-surface" />}>
                    <Sidebar />
                  </Suspense>
                </div>
              </aside>

              <main className="min-w-0 flex-1">{children}</main>
            </div>

            <footer className="border-t border-line py-6 text-sm text-subtle">
              <div className="mx-auto w-full max-w-[100rem] px-4">
                Player counts refresh every few minutes. Uptime is measured from our own checks.
              </div>
            </footer>
          </AuthProvider>
        </ToastProvider>
      </body>
    </html>
  )
}
