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
  bot: (p: Props) => (
    <Svg {...p}>
      <rect x="4" y="8" width="16" height="12" rx="3" />
      <path d="M12 4v4M9 13h.01M15 13h.01M9.5 17h5" />
    </Svg>
  ),
  /* A glyph beside a letter: the standard way to draw "language" without
     picking a flag, which would have to answer whose Spanish this is. */
  language: (p: Props) => (
    <Svg {...p}>
      <path d="M3 6h8M7 6v2c0 3-1.8 5.5-4 6.5M5 10c.8 2.2 2.6 3.9 5 4.5" />
      <path d="m13 20 4-9 4 9M14.5 17h5" />
    </Svg>
  ),
  cloudCheck: (p: Props) => (
    <Svg {...p}>
      <path d="M17.5 17H7a4 4 0 0 1-.6-7.95A5.5 5.5 0 0 1 17.4 9.5a3.75 3.75 0 0 1 .1 7.5Z" />
      <path d="m9.5 13 1.8 1.8L14.5 11" />
    </Svg>
  ),
  cloudOff: (p: Props) => (
    <Svg {...p}>
      <path d="M17.5 17H7a4 4 0 0 1-.6-7.95A5.5 5.5 0 0 1 17.4 9.5a3.75 3.75 0 0 1 .1 7.5Z" />
      <path d="m10 12 4 4M14 12l-4 4" />
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
  plus: (p: Props) => (
    <Svg {...p}>
      <path d="M12 5v14M5 12h14" />
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
  alert: (p: Props) => (
    <Svg {...p}>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7.5v5.5M12 16.5h.01" />
    </Svg>
  ),
  check: (p: Props) => (
    <Svg {...p}>
      <circle cx="12" cy="12" r="9" />
      <path d="m8 12.5 2.5 2.5L16 9.5" />
    </Svg>
  ),
  close: (p: Props) => (
    <Svg {...p}>
      <path d="M6 6l12 12M18 6 6 18" />
    </Svg>
  ),
  menu: (p: Props) => (
    <Svg {...p}>
      <path d="M4 7h16M4 12h16M4 17h16" />
    </Svg>
  ),
  mail: (p: Props) => (
    <Svg {...p}>
      <rect x="3" y="5" width="18" height="14" rx="2" />
      <path d="m3.5 7 8.5 6 8.5-6" />
    </Svg>
  ),
  logout: (p: Props) => (
    <Svg {...p}>
      <path d="M15 12H4M8 8l-4 4 4 4" />
      <path d="M11 4h7a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-7" />
    </Svg>
  ),
  share: (p: Props) => (
    <Svg {...p}>
      <circle cx="18" cy="5" r="3" />
      <circle cx="6" cy="12" r="3" />
      <circle cx="18" cy="19" r="3" />
      <path d="m8.6 10.6 6.8-4.2M8.6 13.4l6.8 4.2" />
    </Svg>
  ),
  chevronLeft: (p: Props) => (
    <Svg {...p}>
      <path d="m14 6-6 6 6 6" />
    </Svg>
  ),
  chevronRight: (p: Props) => (
    <Svg {...p}>
      <path d="m10 6 6 6-6 6" />
    </Svg>
  ),
  /*
   * Brand marks below, not part of the line-drawn set above.
   *
   * These are the logos as their owners publish them — a mark is a filled shape
   * whose entire job is to be recognised before it is read, and redrawing one at
   * 1.75px stroke to match the icon system makes it stop doing that job. Steam
   * and Discord keep their own colour off: on a dark button the single-colour
   * mark is what their brand guidelines ask for anyway. Google's may not be
   * recoloured, so it keeps all four.
   */
  steam: ({ className }: Props) => (
    <svg aria-hidden viewBox="0 0 24 24" fill="currentColor" className={className ?? base}>
      <path d="M11.979 0C5.678 0 .511 4.86.022 11.037l6.432 2.658c.545-.371 1.203-.59 1.912-.59.063 0 .125.004.188.006l2.861-4.142V8.91c0-2.495 2.028-4.524 4.524-4.524 2.494 0 4.524 2.031 4.524 4.527s-2.03 4.525-4.524 4.525h-.105l-4.076 2.911c0 .052.004.105.004.159 0 1.875-1.515 3.396-3.39 3.396-1.635 0-3.016-1.173-3.331-2.727L.436 15.27C1.862 20.307 6.486 24 11.979 24c6.627 0 11.999-5.373 11.999-12S18.605 0 11.979 0zM7.54 18.21l-1.473-.61c.262.543.714.999 1.314 1.25 1.297.539 2.793-.076 3.332-1.375.263-.63.264-1.319.005-1.949s-.75-1.121-1.377-1.383c-.624-.26-1.29-.249-1.878-.03l1.523.63c.956.4 1.409 1.5 1.009 2.455-.397.957-1.497 1.41-2.454 1.012zm11.415-9.303c0-1.662-1.353-3.015-3.015-3.015-1.665 0-3.015 1.353-3.015 3.015 0 1.665 1.35 3.015 3.015 3.015 1.663 0 3.015-1.35 3.015-3.015zm-5.273-.005c0-1.252 1.013-2.266 2.265-2.266 1.249 0 2.266 1.014 2.266 2.266 0 1.251-1.017 2.265-2.266 2.265-1.253 0-2.265-1.014-2.265-2.265z" />
    </svg>
  ),
  discord: ({ className }: Props) => (
    <svg aria-hidden viewBox="0 0 24 24" fill="currentColor" className={className ?? base}>
      <path d="M20.317 4.3697a19.7913 19.7913 0 0 0-4.8851-1.5152.0741.0741 0 0 0-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 0 0-.0785-.037 19.7363 19.7363 0 0 0-4.8852 1.515.0699.0699 0 0 0-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 0 0 .0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 0 0 .0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 0 0-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 0 1-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 0 1 .0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 0 1 .0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 0 1-.0066.1276 12.2986 12.2986 0 0 1-1.873.8914.0766.0766 0 0 0-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 0 0 .0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 0 0 .0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 0 0-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189z" />
    </svg>
  ),
  google: ({ className }: Props) => (
    <svg aria-hidden viewBox="0 0 24 24" className={className ?? base}>
      <path
        fill="#4285F4"
        d="M21.6 12.23c0-.71-.06-1.4-.18-2.05H12v3.88h5.38a4.6 4.6 0 0 1-2 3.02v2.5h3.24c1.9-1.74 2.98-4.3 2.98-7.35Z"
      />
      <path
        fill="#34A853"
        d="M12 22c2.7 0 4.96-.9 6.62-2.42l-3.24-2.5c-.9.6-2.04.96-3.38.96-2.6 0-4.8-1.76-5.59-4.12H3.06v2.58A10 10 0 0 0 12 22Z"
      />
      <path
        fill="#FBBC05"
        d="M6.41 13.92a6 6 0 0 1 0-3.84V7.5H3.06a10 10 0 0 0 0 9l3.35-2.58Z"
      />
      <path
        fill="#EA4335"
        d="M12 5.94c1.47 0 2.79.5 3.83 1.5l2.87-2.87C16.95 2.98 14.7 2 12 2a10 10 0 0 0-8.94 5.5l3.35 2.58C7.2 7.7 9.4 5.94 12 5.94Z"
      />
    </svg>
  ),
}
