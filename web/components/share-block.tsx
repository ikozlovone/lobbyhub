'use client'

import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { Icon } from './icons'
import { MARKS, type Mark } from './share-icons'
import { useToast } from './toast'

/**
 * Share links plus a copyable URL.
 *
 * Plain anchors to each network's share endpoint — no third-party SDKs, which
 * would mean shipping their tracking to every visitor for a button.
 *
 * The list is long on purpose. A server's players are wherever they are, and a
 * row that scrolls costs nothing to the visitor who only ever wanted the first
 * two: the ones that matter to most people lead, the regional ones follow, and
 * the rail is one gesture wide either way.
 */

type Target = {
  name: string
  mark?: Mark
  /** Both parts arrive already percent-encoded. */
  href: (url: string, title: string) => string
}

const TARGETS: Target[] = [
  { name: 'WhatsApp', mark: MARKS.whatsapp, href: (u, t) => `https://api.whatsapp.com/send?text=${t}%20${u}` },
  { name: 'Telegram', mark: MARKS.telegram, href: (u, t) => `https://t.me/share/url?url=${u}&text=${t}` },
  { name: 'X', mark: MARKS.x, href: (u, t) => `https://x.com/intent/post?url=${u}&text=${t}` },
  { name: 'Facebook', mark: MARKS.facebook, href: (u) => `https://www.facebook.com/sharer/sharer.php?u=${u}` },
  { name: 'Reddit', mark: MARKS.reddit, href: (u, t) => `https://www.reddit.com/submit?url=${u}&title=${t}` },
  { name: 'Bluesky', mark: MARKS.bluesky, href: (u, t) => `https://bsky.app/intent/compose?text=${t}%20${u}` },
  { name: 'Threads', mark: MARKS.threads, href: (u, t) => `https://www.threads.net/intent/post?text=${t}%20${u}` },
  // Mastodon is a network of independent servers with no shared front door, so
  // the link goes through the community's instance picker.
  { name: 'Mastodon', mark: MARKS.mastodon, href: (u, t) => `https://mastodonshare.com/?url=${u}&text=${t}` },
  { name: 'Tumblr', mark: MARKS.tumblr, href: (u, t) => `https://www.tumblr.com/widgets/share/tool?canonicalUrl=${u}&title=${t}` },
  { name: 'Pinterest', mark: MARKS.pinterest, href: (u, t) => `https://www.pinterest.com/pin/create/button/?url=${u}&description=${t}` },
  { name: 'LINE', mark: MARKS.line, href: (u, t) => `https://social-plugins.line.me/lineit/share?url=${u}&text=${t}` },
  { name: 'Viber', mark: MARKS.viber, href: (u, t) => `viber://forward?text=${t}%20${u}` },
  { name: 'Threema', mark: MARKS.threema, href: (u, t) => `https://threema.id/compose?text=${t}%20${u}` },
  { name: 'VK', mark: MARKS.vk, href: (u, t) => `https://vk.com/share.php?url=${u}&title=${t}` },
  { name: 'OK', mark: MARKS.odnoklassniki, href: (u, t) => `https://connect.ok.ru/offer?url=${u}&title=${t}` },
  { name: 'Mail.ru', mark: MARKS.maildotru, href: (u, t) => `https://connect.mail.ru/share?url=${u}&title=${t}` },
  { name: 'Weibo', mark: MARKS.sinaweibo, href: (u, t) => `https://service.weibo.com/share/share.php?url=${u}&title=${t}` },
  { name: 'QQ', mark: MARKS.qq, href: (u, t) => `https://connect.qq.com/widget/shareqq/index.html?url=${u}&title=${t}` },
  { name: 'Qzone', mark: MARKS.qzone, href: (u, t) => `https://sns.qzone.qq.com/cgi-bin/qzshare/cgi_qzshare_onekey?url=${u}&title=${t}` },
  { name: 'Douban', mark: MARKS.douban, href: (u, t) => `https://www.douban.com/share/service?href=${u}&name=${t}` },
  { name: 'Baidu Tieba', mark: MARKS.baidu, href: (u, t) => `https://tieba.baidu.com/f/commit/share/openShareApi?url=${u}&title=${t}` },
  { name: 'Naver', mark: MARKS.naver, href: (u, t) => `https://share.naver.com/web/shareView?url=${u}&title=${t}` },
  { name: 'Hatena', mark: MARKS.hatenabookmark, href: (u, t) => `https://b.hatena.ne.jp/entry/panel/?url=${u}&title=${t}` },
  { name: 'Hacker News', mark: MARKS.ycombinator, href: (u, t) => `https://news.ycombinator.com/submitlink?u=${u}&t=${t}` },
  { name: 'LiveJournal', mark: MARKS.livejournal, href: (u, t) => `https://www.livejournal.com/update.bml?subject=${t}&event=${u}` },
  { name: 'Blogger', mark: MARKS.blogger, href: (u, t) => `https://www.blogger.com/blog-this.g?u=${u}&n=${t}` },
  { name: 'Flipboard', mark: MARKS.flipboard, href: (u, t) => `https://share.flipboard.com/bookmarklet/popout?v=2&url=${u}&title=${t}` },
  { name: 'Wykop', mark: MARKS.wykop, href: (u, t) => `https://www.wykop.pl/dodaj/link/?url=${u}&title=${t}` },
  { name: 'Draugiem.lv', mark: MARKS.draugiemdotlv, href: (u, t) => `https://www.draugiem.lv/say/ext/add.php?url=${u}&title=${t}` },
  { name: 'diaspora*', mark: MARKS.diaspora, href: (u, t) => `https://share.diasporafoundation.org/?url=${u}&title=${t}` },
  { name: 'XING', mark: MARKS.xing, href: (u) => `https://www.xing.com/spi/shares/new?url=${u}` },
  { name: 'Email', href: (u, t) => `mailto:?subject=${t}&body=${u}` },
]

/** The colour used when a target has no brand of its own. */
const NEUTRAL = '#4b5563'

export function ShareBlock({ url, name }: { url: string; name: string }) {
  const [copied, setCopied] = useState(false)
  const toast = useToast()

  const encodedUrl = encodeURIComponent(url)
  const encodedTitle = encodeURIComponent(name)

  async function copy() {
    await navigator.clipboard.writeText(url)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
    toast.success('Copied!', 'Content has been successfully copied to the clipboard.')
  }

  return (
    <section className="rounded-lg border border-line bg-surface">
      <h2 className="font-display border-b border-line px-4 py-3 text-sm font-bold tracking-wide uppercase">
        Share
      </h2>

      <div className="space-y-2 p-3">
        <Rail>
          <NativeShare url={url} name={name} />

          {TARGETS.map((target) => (
            <li key={target.name}>
              <a
                href={target.href(encodedUrl, encodedTitle)}
                target="_blank"
                rel="noopener noreferrer"
                className={BUTTON}
              >
                <Badge mark={target.mark}>
                  {target.mark ? (
                    <BrandMark mark={target.mark} />
                  ) : (
                    <Icon.mail className="size-6" />
                  )}
                </Badge>
                <Label>{target.name}</Label>
              </a>
            </li>
          ))}
        </Rail>

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

const BUTTON =
  'group flex w-14 cursor-pointer flex-col items-center gap-1 text-center outline-none'

/**
 * The operating system's own share sheet.
 *
 * First in the row where it exists, because it reaches the apps a web page
 * cannot name — and absent where it does not, since a button that opens nothing
 * is worse than no button. Support is read after mount: the server has no way
 * to know, and guessing produces a different first paint than the browser's.
 */
function NativeShare({ url, name }: { url: string; name: string }) {
  const supported = useSyncExternalStore(
    () => () => {},
    () => typeof navigator.share === 'function',
    () => false,
  )

  if (!supported) return null

  return (
    <li>
      <button
        type="button"
        className={BUTTON}
        onClick={() => {
          // A cancelled sheet rejects, and there is nothing to report about a
          // visitor changing their mind.
          void navigator.share({ title: name, url }).catch(() => {})
        }}
      >
        <Badge>
          <Icon.share className="size-6" />
        </Badge>
        <Label>Share</Label>
      </button>
    </li>
  )
}

/**
 * The round, brand-coloured disc a mark sits on.
 *
 * White marks on the brand colour, which is how their owners publish them, with
 * two exceptions the page forces. A brand whose colour is black — X's and
 * Threads' are — gets the inverse of its lockup, because on this near-black card
 * a black disc is a hole, and both publish a black-on-white form for exactly
 * that case. And a brand pale enough that a white mark on it stops being a mark
 * at all, Qzone's yellow, takes a dark one instead.
 *
 * The line is drawn on contrast rather than on brightness: brand greens are
 * bright too, but their white lockup is the form people recognise, and swapping
 * it for a black one to buy a little legibility loses more than it gains.
 */
function Badge({ mark, children }: { mark?: Mark; children: React.ReactNode }) {
  const brand = mark?.hex ?? NEUTRAL
  const onBrand = 1.05 / (luminance(brand) + 0.05)
  const black = onBrand > 15
  const light = onBrand < 1.6

  return (
    <span
      className="flex size-14 items-center justify-center rounded-full shadow-sm transition-transform group-hover:scale-105 group-focus-visible:scale-105 group-focus-visible:ring-2 group-focus-visible:ring-fg"
      style={{
        backgroundColor: black ? '#ffffff' : brand,
        color: black || light ? '#0b0b0b' : '#ffffff',
      }}
    >
      {children}
    </span>
  )
}

function Label({ children }: { children: React.ReactNode }) {
  return (
    <span className="line-clamp-2 w-full text-[10px] leading-3 text-subtle transition-colors group-hover:text-fg">
      {children}
    </span>
  )
}

function BrandMark({ mark }: { mark: Mark }) {
  return (
    <svg aria-hidden viewBox="0 0 24 24" fill="currentColor" className="size-6 shrink-0">
      <path d={mark.path} />
    </svg>
  )
}

/**
 * The scrolling row, with a control at each end.
 *
 * The arrows are for the pointer: a trackpad or a finger already flicks the row
 * sideways, but a mouse has no such gesture and would otherwise be left dragging
 * a scrollbar we hide. Each one appears only while there is something in that
 * direction to reach, so they double as the sign that the row continues.
 */
function Rail({ children }: { children: React.ReactNode }) {
  const track = useRef<HTMLDivElement>(null)
  const [reach, setReach] = useState({ back: false, forward: false })

  const measure = useCallback(() => {
    const element = track.current

    if (!element) return

    const end = element.scrollWidth - element.clientWidth

    setReach({ back: element.scrollLeft > 1, forward: element.scrollLeft < end - 1 })
  }, [])

  useEffect(() => {
    const element = track.current

    if (!element) return

    measure()

    // The row's own width changes with the column it sits in, and the buttons
    // arrive with it — a resize is as much a reason to re-measure as a scroll.
    const observer = new ResizeObserver(measure)

    observer.observe(element)

    return () => observer.disconnect()
  }, [measure])

  function scroll(direction: 1 | -1) {
    track.current?.scrollBy({ left: direction * 160, behavior: 'smooth' })
  }

  return (
    <div className="relative">
      <div
        ref={track}
        onScroll={measure}
        className="overflow-x-auto overflow-y-hidden overscroll-x-contain [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
      >
        <ul className="flex w-max min-w-full gap-3 py-1">{children}</ul>
      </div>

      <Arrow side="left" shown={reach.back} onClick={() => scroll(-1)} />
      <Arrow side="right" shown={reach.forward} onClick={() => scroll(1)} />
    </div>
  )
}

function Arrow({
  side,
  shown,
  onClick,
}: {
  side: 'left' | 'right'
  shown: boolean
  onClick: () => void
}) {
  const Glyph = side === 'left' ? Icon.chevronLeft : Icon.chevronRight

  return (
    <button
      type="button"
      onClick={onClick}
      // Kept out of the tab order: the links it scrolls to are already in it,
      // and focusing one scrolls it into view by itself.
      tabIndex={-1}
      aria-hidden
      // Centred on the discs rather than on the row: the labels below them are
      // not what an arrow points past.
      className={`absolute top-8 flex size-8 -translate-y-1/2 cursor-pointer items-center justify-center rounded-full border border-line bg-surface-2/95 text-muted shadow-lg transition-opacity hover:text-fg ${
        side === 'left' ? 'left-0' : 'right-0'
      } ${shown ? 'opacity-100' : 'pointer-events-none opacity-0'}`}
    >
      <Glyph />
    </button>
  )
}

/** Perceived brightness of a hex colour, 0 to 1. */
function luminance(hex: string) {
  const value = parseInt(hex.slice(1), 16)
  const channels = [(value >> 16) & 255, (value >> 8) & 255, value & 255].map((channel) => {
    const part = channel / 255

    return part <= 0.03928 ? part / 12.92 : ((part + 0.055) / 1.055) ** 2.4
  })

  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2]
}
