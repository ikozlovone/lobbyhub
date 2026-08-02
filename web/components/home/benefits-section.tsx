import { Icon } from '../icons'
import { HOME_COPY } from './copy'

/**
 * Why bother with this site rather than a server list scraped from a forum.
 *
 * Each of the four names something that exists today — live queries, the filter
 * chips on a game listing, the history chart on a server page, and the catalog
 * itself. "Server History" says days and weeks rather than "important server
 * events", because events are not something we record.
 */
const ICONS = [Icon.gauge, Icon.boxes, Icon.chart, Icon.users] as const

export function BenefitsSection() {
  return (
    <section aria-labelledby="section-benefits" className="space-y-4">
      <h2 id="section-benefits" className="font-display text-xl font-black tracking-tight uppercase">
        {HOME_COPY.benefits.title}
      </h2>

      <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {HOME_COPY.benefits.items.map((item, index) => {
          const Glyph = ICONS[index] ?? Icon.info

          return (
            <li
              key={item.title}
              className="rounded-xl border border-line bg-surface p-4 transition-colors hover:border-line-strong"
            >
              <span
                aria-hidden
                className="flex size-9 items-center justify-center rounded-lg bg-brand/15 text-brand"
              >
                <Glyph className="size-5" />
              </span>
              <h3 className="mt-3 font-medium text-fg">{item.title}</h3>
              <p className="mt-1 text-sm text-muted">{item.detail}</p>
            </li>
          )
        })}
      </ul>
    </section>
  )
}
