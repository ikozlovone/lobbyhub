/**
 * What a visitor has allowed us to keep on their device.
 *
 * Under the ePrivacy Directive — the rule that actually governs cookies and
 * localStorage, not the GDPR — anything not strictly necessary to deliver the
 * page needs consent *before* it is stored, and the GDPR sets the bar for what
 * consent is: freely given, specific, informed, unambiguous, and as easy to
 * withdraw as to give. Three things follow, and they are why this file exists:
 *
 *  - Default is denied. There is no "consent by continuing to browse".
 *  - Each purpose is asked for separately, so a visitor can take analytics and
 *    refuse advertising.
 *  - The choice is stored with the version of the question it answered, so
 *    adding a purpose re-asks instead of silently inheriting an old yes.
 *
 * The session token and the favourites list are not in here on purpose: both
 * are strictly necessary to do the thing the visitor asked for, and asking
 * permission to keep somebody signed in is asking a question with one answer.
 */

/** Purposes a visitor can be asked about. Necessary storage is not one of them. */
export type ConsentCategory = 'analytics' | 'advertising'

export const CATEGORY_LABELS: Record<ConsentCategory, { title: string; detail: string }> = {
  analytics: {
    title: 'Analytics',
    detail:
      'Which pages get visited and which searches come up empty, so we know what to fix. Never used to build a profile of you.',
  },
  advertising: {
    title: 'Advertising',
    detail:
      'Lets our advertising partners store an identifier and measure whether an ad worked. Refusing does not remove the ads — it means they are chosen without knowing anything about you.',
  },
}

export const CONSENT_KEY = 'lobbyhub.consent'

/**
 * Bump when a new purpose is added or an existing one starts doing more than it
 * said. A stored choice from an older version is not an answer to the current
 * question, so it is treated as no answer at all and asked again.
 */
export const CONSENT_VERSION = 1

export type ConsentChoice = {
  version: number
  /** When the choice was made. A consent nobody can date is a consent nobody can defend. */
  at: string
  granted: ConsentCategory[]
}

const ALL: ConsentCategory[] = ['analytics', 'advertising']

/**
 * Purposes this deployment actually runs something for.
 *
 * Empty by default, and today that is the truth: the site loads no analytics
 * and no ad script, so there is nothing to ask about and no banner is shown.
 * Asking anyway would be theatre — and a banner that appears when nothing is
 * being stored trains people to dismiss the one that matters. Set
 * NEXT_PUBLIC_CONSENT_CATEGORIES the same day the tag goes in, not after.
 */
export const CONFIGURED: ConsentCategory[] = (process.env.NEXT_PUBLIC_CONSENT_CATEGORIES ?? '')
  .split(',')
  .map((name) => name.trim())
  .filter((name): name is ConsentCategory => (ALL as string[]).includes(name))

/**
 * The stored choice, or null if there is none to honour.
 *
 * Storage throws rather than returns null in a locked-down browser (Safari's
 * private mode, embedded webviews), and a visitor whose browser refuses to
 * remember the answer is a visitor who has not answered.
 */
export function readConsent(): ConsentChoice | null {
  try {
    const raw = localStorage.getItem(CONSENT_KEY)

    if (!raw) return null

    const parsed = JSON.parse(raw) as ConsentChoice

    if (parsed?.version !== CONSENT_VERSION || !Array.isArray(parsed.granted)) return null

    return {
      version: parsed.version,
      at: typeof parsed.at === 'string' ? parsed.at : '',
      // Filtered, not trusted: hand-edited storage should not be able to turn on
      // a purpose that does not exist.
      granted: parsed.granted.filter((name): name is ConsentCategory =>
        (ALL as string[]).includes(name),
      ),
    }
  } catch {
    return null
  }
}

export function writeConsent(granted: ConsentCategory[], now: Date): ConsentChoice {
  const choice: ConsentChoice = {
    version: CONSENT_VERSION,
    at: now.toISOString(),
    granted: ALL.filter((name) => granted.includes(name)),
  }

  try {
    localStorage.setItem(CONSENT_KEY, JSON.stringify(choice))
  } catch {
    // A browser that will not store the answer will ask again next time. That is
    // the correct failure: it errs towards not running things nobody allowed.
  }

  return choice
}

export function clearConsent() {
  try {
    localStorage.removeItem(CONSENT_KEY)
  } catch {
    /* nothing stored, nothing to clear */
  }
}
