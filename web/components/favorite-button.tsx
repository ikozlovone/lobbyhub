'use client'

import { Icon } from './icons'
import { useFavorites } from './favorites-provider'

/**
 * The star that puts a server on the visitor's own list.
 *
 * Shown to everyone, signed in or not. Hiding it from anonymous visitors would
 * make the feature invisible to exactly the people who have not discovered it;
 * pressing it while signed out opens the sign-in dialog, which is what the vote
 * and claim buttons do with the same problem.
 *
 * The filled state is `currentColor` on the same path, so there is one shape and
 * no second icon to keep in step with it.
 */
export function FavoriteButton({
  slug,
  name,
  className = '',
  size = 'size-4',
}: {
  slug: string
  /** Named in the label, because a page can carry twenty-five of these. */
  name: string
  className?: string
  size?: string
}) {
  const { slugs, toggle } = useFavorites()
  const starred = slugs?.has(slug) ?? false

  return (
    <button
      type="button"
      onClick={(event) => {
        // Stars sit inside rows and cards that are themselves links.
        event.preventDefault()
        event.stopPropagation()
        toggle(slug)
      }}
      aria-pressed={starred}
      aria-label={starred ? `Remove ${name} from favorites` : `Add ${name} to favorites`}
      title={starred ? 'Remove from favorites' : 'Add to favorites'}
      className={`cursor-pointer transition-colors ${
        starred ? 'text-accent' : 'text-subtle hover:text-fg'
      } ${className}`}
    >
      <Icon.star className={`${size} ${starred ? 'fill-current' : ''}`} />
    </button>
  )
}
