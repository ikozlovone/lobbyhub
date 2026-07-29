'use client'

import { Icon } from './icons'
import { useToast } from './toast'

/**
 * The address, and the two things anyone does with it.
 *
 * Copying is the universal path — every one of these games takes a pasted
 * address in its console. The green arrow is the shortcut: Steam registers a
 * `steam://connect` handler, so for a Source-protocol server the browser can
 * hand the join straight to the game client.
 */
export function ConnectActions({
  address,
  steam,
  className,
}: {
  address: string
  /** Whether this game's client can be launched into a server by Steam. */
  steam: boolean
  className?: string
}) {
  const toast = useToast()

  async function copy() {
    await navigator.clipboard.writeText(address)
    toast.success('Copied!', 'Content has been successfully copied to the clipboard.')
  }

  return (
    <span className={`flex items-center gap-1 ${className ?? ''}`}>
      <span className="tabular truncate text-xs text-muted">{address}</span>

      <button
        type="button"
        onClick={copy}
        aria-label={`Copy ${address}`}
        title="Copy address"
        className="shrink-0 cursor-pointer rounded p-1 text-subtle transition-colors hover:bg-surface-2 hover:text-fg"
      >
        <Icon.copy className="size-3.5" />
      </button>

      {steam && (
        <a
          href={`steam://connect/${address}`}
          aria-label={`Connect to ${address} through Steam`}
          title="Connect"
          className="shrink-0 cursor-pointer rounded p-1 text-subtle transition-colors hover:bg-surface-2 hover:text-brand"
        >
          <Icon.play className="size-3.5" />
        </a>
      )}
    </span>
  )
}
