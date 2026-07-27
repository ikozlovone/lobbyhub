import type { Metadata } from 'next'
import { Inter, JetBrains_Mono, Orbitron } from 'next/font/google'
import Link from 'next/link'
import { Suspense } from 'react'
import { SearchBox } from '@/components/search-box'
import { Sidebar } from '@/components/sidebar'
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

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en" className={`${orbitron.variable} ${inter.variable} ${mono.variable} h-full`}>
      <body className="flex min-h-full flex-col">
        <header className="sticky top-0 z-20 border-b border-line bg-bg/90 backdrop-blur">
          <div className="mx-auto flex h-14 w-full max-w-[100rem] items-center gap-4 px-4">
            <Link
              href="/"
              className="font-display shrink-0 text-lg font-black tracking-tight transition-colors hover:text-brand"
            >
              LOBBY<span className="text-brand">HUB</span>
            </Link>
            <div className="flex flex-1 justify-center">
              <SearchBox apiUrl={API_URL} />
            </div>
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
      </body>
    </html>
  )
}
