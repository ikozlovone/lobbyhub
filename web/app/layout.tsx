import type { Metadata } from 'next'
import { Inter, JetBrains_Mono, Orbitron } from 'next/font/google'
import Link from 'next/link'
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

export const metadata: Metadata = {
  metadataBase: new URL(process.env.NEXT_PUBLIC_SITE_URL ?? 'http://localhost:3000'),
  title: {
    default: 'LobbyHub — game server monitoring and top lists',
    template: '%s | LobbyHub',
  },
  description:
    'Live player counts, uptime history and rankings for Minecraft, Rust and FiveM servers.',
}

const NAV = [
  { href: '/games/minecraft', label: 'Minecraft' },
  { href: '/games/rust', label: 'Rust' },
  { href: '/games/fivem', label: 'FiveM' },
]

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en" className={`${orbitron.variable} ${inter.variable} ${mono.variable} h-full`}>
      <body className="flex min-h-full flex-col">
        <header className="sticky top-0 z-20 border-b border-line bg-bg/85 backdrop-blur">
          <div className="mx-auto flex h-14 w-full max-w-7xl items-center gap-6 px-4">
            <Link
              href="/"
              className="font-display text-lg font-black tracking-tight transition-colors hover:text-brand"
            >
              LOBBY<span className="text-brand">HUB</span>
            </Link>
            <nav className="flex items-center gap-4 text-sm text-muted">
              {NAV.map((item) => (
                <Link key={item.href} href={item.href} className="transition-colors hover:text-fg">
                  {item.label}
                </Link>
              ))}
            </nav>
          </div>
        </header>

        <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-8">{children}</main>

        <footer className="mt-16 border-t border-line py-8 text-sm text-subtle">
          <div className="mx-auto w-full max-w-7xl px-4">
            Player counts refresh every few minutes. Uptime is measured from our own checks.
          </div>
        </footer>
      </body>
    </html>
  )
}
