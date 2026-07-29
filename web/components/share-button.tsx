'use client'

import { Icon } from './icons'
import { useToast } from './toast'

/**
 * Share this page.
 *
 * Hands off to the browser's own share sheet where there is one, which is
 * phones — where sharing a listing actually happens. Desktop browsers have no
 * sheet, and there the honest equivalent of sharing is putting the address on
 * the clipboard, which is what every other copy control here does.
 */
export function ShareButton({ title }: { title: string }) {
  const toast = useToast()

  async function share() {
    const url = window.location.href

    if (typeof navigator.share === 'function') {
      // Dismissing the sheet rejects too; neither outcome is worth a toast.
      await navigator.share({ title, url }).catch(() => {})

      return
    }

    await navigator.clipboard.writeText(url)
    toast.success('Copied!', 'Content has been successfully copied to the clipboard.')
  }

  return (
    <button
      type="button"
      onClick={share}
      className="flex cursor-pointer items-center gap-2 rounded-lg border border-line-strong bg-surface/80 px-4 py-2 text-sm font-medium backdrop-blur transition-colors hover:bg-surface-2"
    >
      <Icon.link />
      Share
    </button>
  )
}
