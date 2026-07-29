'use client'

import type { Country } from '@/lib/api'

/**
 * Where a server is, as a flag and its code.
 *
 * Both, not either. The flag is what the eye finds while scanning a column of
 * three hundred rows; the code is what stays readable when it does not — half
 * the world's flags are three stripes, and at twenty pixels several pairs are
 * genuinely the same picture. The code is also the fallback: countries come
 * from a GeoIP database that knows more of them than any icon set ships, and a
 * missing file leaves the label rather than a broken image.
 *
 * Files come from public/flags — see scripts/sync-flags.mjs.
 */
export function CountryFlag({
  country,
  city,
  className,
}: {
  country: Country
  /** Shown in the tooltip when we resolved the address that far. */
  city?: string | null
  className?: string
}) {
  const code = country.code.toLowerCase()

  return (
    <span
      className={`flex shrink-0 items-center gap-1.5 ${className ?? ''}`}
      title={city ? `${country.name} — ${city}` : country.name}
    >
      <img
        src={`/flags/${code}.svg`}
        // Decorative: the code beside it says the same thing, and a screen
        // reader announcing a flag on every row of a listing is noise.
        alt=""
        width={16}
        height={11}
        loading="lazy"
        // Nothing here is worth an optimizer pass: these are already under a
        // kilobyte, and SVG has neither a size to pick nor a format to convert.
        className="h-[11px] w-4 rounded-[1px] object-cover ring-1 ring-black/25"
        // A flag the set does not carry leaves the code standing alone.
        onError={(event) => {
          event.currentTarget.style.display = 'none'
        }}
      />
      {/*<span className="text-[11px] tracking-wide text-subtle uppercase">{country.code}</span>*/}
    </span>
  )
}
