/**
 * The handful of icons this UI needs, inlined.
 *
 * Inline rather than an icon package: a dozen paths do not justify a dependency,
 * and these ship as part of the markup instead of a separate request. Every one
 * is decorative — labels carry the meaning — so they are aria-hidden.
 */

type Props = { className?: string }

const base = 'size-4 shrink-0'

function Svg({ children, className }: Props & { children: React.ReactNode }) {
  return (
    <svg
      aria-hidden
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.75}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className ?? base}
    >
      {children}
    </svg>
  )
}

export const Icon = {
  users: (p: Props) => (
    <Svg {...p}>
      <path d="M16 21v-2a4 4 0 0 0-8 0v2" />
      <circle cx="12" cy="7" r="4" />
    </Svg>
  ),
  globe: (p: Props) => (
    <Svg {...p}>
      <circle cx="12" cy="12" r="9" />
      <path d="M3 12h18M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18" />
    </Svg>
  ),
  map: (p: Props) => (
    <Svg {...p}>
      <path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2-6-2Z" />
      <path d="M9 4v14M15 6v14" />
    </Svg>
  ),
  ruler: (p: Props) => (
    <Svg {...p}>
      <path d="M3 15 15 3l6 6L9 21Z" />
      <path d="M7 11l2 2M11 7l2 2" />
    </Svg>
  ),
  seed: (p: Props) => (
    <Svg {...p}>
      <path d="M12 21V9" />
      <path d="M12 9a6 6 0 0 1 6-6a6 6 0 0 1-6 6Z" />
      <path d="M12 13a5 5 0 0 0-5-5a5 5 0 0 0 5 5Z" />
    </Svg>
  ),
  mode: (p: Props) => (
    <Svg {...p}>
      <path d="M4 6h16M4 12h10M4 18h7" />
    </Svg>
  ),
  tag: (p: Props) => (
    <Svg {...p}>
      <path d="M20.6 13.4 12 22l-9-9 8.6-8.6A2 2 0 0 1 13 4h6a1 1 0 0 1 1 1v6a2 2 0 0 1-.4 1.4Z" />
      <circle cx="16" cy="8" r="1.2" />
    </Svg>
  ),
  shield: (p: Props) => (
    <Svg {...p}>
      <path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3Z" />
    </Svg>
  ),
  gauge: (p: Props) => (
    <Svg {...p}>
      <path d="M12 14 15.5 9" />
      <path d="M3.5 17a9 9 0 1 1 17 0" />
    </Svg>
  ),
  boxes: (p: Props) => (
    <Svg {...p}>
      <path d="M12 3l4 2v4l-4 2-4-2V5l4-2Z" />
      <path d="M6 12l4 2v4l-4 2-4-2v-4l4-2Z" />
      <path d="M18 12l4 2v4l-4 2-4-2v-4l4-2Z" />
    </Svg>
  ),
  clock: (p: Props) => (
    <Svg {...p}>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7v5l3 2" />
    </Svg>
  ),
  refresh: (p: Props) => (
    <Svg {...p}>
      <path d="M21 12a9 9 0 1 1-3-6.7" />
      <path d="M21 4v5h-5" />
    </Svg>
  ),
  copy: (p: Props) => (
    <Svg {...p}>
      <rect x="9" y="9" width="12" height="12" rx="2" />
      <path d="M5 15V5a2 2 0 0 1 2-2h8" />
    </Svg>
  ),
  play: (p: Props) => (
    <Svg {...p}>
      <path d="M7 4l12 8-12 8V4Z" />
    </Svg>
  ),
  search: (p: Props) => (
    <Svg {...p}>
      <circle cx="11" cy="11" r="7" />
      <path d="m20 20-3.5-3.5" />
    </Svg>
  ),
  star: (p: Props) => (
    <Svg {...p}>
      <path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3 6.4 20.2l1.1-6.2L3 9.6l6.2-.9L12 3Z" />
    </Svg>
  ),
  info: (p: Props) => (
    <Svg {...p}>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 11v5M12 8h.01" />
    </Svg>
  ),
  chart: (p: Props) => (
    <Svg {...p}>
      <path d="M4 20V10M10 20V4M16 20v-7M22 20H2" />
    </Svg>
  ),
  link: (p: Props) => (
    <Svg {...p}>
      <path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1" />
      <path d="M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1" />
    </Svg>
  ),
  steam: (p: Props) => (
    <Svg {...p}>
      <circle cx="12" cy="12" r="9" />
      <circle cx="15" cy="9" r="2.5" />
      <path d="M3.5 15l5-2" />
    </Svg>
  ),
}
