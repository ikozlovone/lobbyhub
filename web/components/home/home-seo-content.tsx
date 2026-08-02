import { HOME_COPY } from './copy'

/**
 * The paragraph block at the foot of the page.
 *
 * Visible, in the markup, not behind a "read more" toggle — a crawler discounts
 * text a visitor cannot see, and hiding it would be the tell that it was
 * written for the crawler. Which is why the third paragraph says something a
 * person might actually want to know: where the numbers come from.
 */
export function HomeSeoContent() {
  return (
    <section
      aria-labelledby="section-about"
      className="rounded-2xl border border-line bg-surface/60 p-6 sm:p-8"
    >
      <h2 id="section-about" className="font-display text-xl font-black tracking-tight uppercase">
        {HOME_COPY.seo.title}
      </h2>

      <div className="mt-3 max-w-[75ch] space-y-3 text-sm leading-relaxed text-muted">
        {HOME_COPY.seo.paragraphs.map((paragraph) => (
          <p key={paragraph.slice(0, 32)}>{paragraph}</p>
        ))}
      </div>
    </section>
  )
}
