'use client'

import { useState } from 'react'
import { Icon } from './icons'

/**
 * Share links plus a copyable URL.
 *
 * Plain anchors to each network's share endpoint — no third-party SDKs, which
 * would mean shipping their tracking to every visitor for a button.
 */
const TARGETS = [
  { name: 'Telegram', href: (u: string, t: string) => `https://t.me/share/url?url=${u}&text=${t}` },
  { name: 'X', href: (u: string, t: string) => `https://x.com/intent/post?url=${u}&text=${t}` },
  { name: 'Reddit', href: (u: string, t: string) => `https://reddit.com/submit?url=${u}&title=${t}` },
  { name: 'VK', href: (u: string, t: string) => `https://vk.com/share.php?url=${u}&title=${t}` },
]

export function ShareBlock({ url, name }: { url: string; name: string }) {
  const [copied, setCopied] = useState(false)

  const encodedUrl = encodeURIComponent(url)
  const encodedTitle = encodeURIComponent(name)

  async function copy() {
    await navigator.clipboard.writeText(url)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <section className="rounded-lg border border-line bg-surface">
      <h2 className="font-display border-b border-line px-4 py-3 text-sm font-bold tracking-wide uppercase">
        Share
      </h2>

      <div className="space-y-2 p-3">
        <ul className="grid grid-cols-4 gap-2">
          {TARGETS.map((target) => (
            <li key={target.name}>
              <a
                href={target.href(encodedUrl, encodedTitle)}
                target="_blank"
                rel="noopener noreferrer"
                className="flex cursor-pointer items-center justify-center rounded-md border border-line bg-bg py-2 text-[11px] text-muted transition-colors hover:border-line-strong hover:text-fg"
              >
                {target.name}
              </a>
            </li>
          ))}
        </ul>

        <button
          type="button"
          onClick={copy}
          className="flex w-full cursor-pointer items-center gap-2 rounded-md border border-line bg-bg px-3 py-2 text-left text-xs transition-colors hover:border-line-strong"
          aria-label="Copy page link"
        >
          <span className="min-w-0 flex-1 truncate text-muted">{url}</span>
          <span className={copied ? 'text-brand' : 'text-subtle'}>
            <Icon.copy />
          </span>
        </button>
      </div>
    </section>
  )
}
