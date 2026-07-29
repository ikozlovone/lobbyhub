'use client'

import { useEffect, useState } from 'react'

const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
  ['year', 31_536_000],
  ['month', 2_592_000],
  ['day', 86_400],
  ['hour', 3_600],
  ['minute', 60],
]

const relative = new Intl.RelativeTimeFormat('en-US', { numeric: 'auto' })

/**
 * "8 minutes ago", computed in the browser.
 *
 * Page shells are cached for minutes to hours, so a relative label rendered on
 * the server would be stale for exactly as long — and "just now" is the one
 * phrase that must never be. The absolute date is what ships in the HTML; the
 * relative reading replaces it after hydration and stays true from there.
 */
export function RelativeTime({ at, className }: { at: string; className?: string }) {
  const date = new Date(at)
  const absolute = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
  const [label, setLabel] = useState(absolute)

  useEffect(() => {
    const tick = () => setLabel(format(date))

    tick()
    const timer = setInterval(tick, 60_000)

    return () => clearInterval(timer)
    // The timestamp is the only input; `date` is derived from it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [at])

  return (
    <time dateTime={at} title={date.toLocaleString('en-US')} className={className}>
      {label}
    </time>
  )
}

function format(date: Date): string {
  const seconds = Math.round((date.getTime() - Date.now()) / 1000)

  for (const [unit, size] of UNITS) {
    if (Math.abs(seconds) >= size) {
      return relative.format(Math.round(seconds / size), unit)
    }
  }

  return 'just now'
}
