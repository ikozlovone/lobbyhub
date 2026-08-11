/**
 * Where and how to reach the site's owners.
 *
 * Constants rather than fields on CONTROLLER because they are not GDPR
 * requirements the way an address and an email are — Discord and Telegram
 * exist only if we run them, and a placeholder for a chat that does not exist
 * yet is worse than the absence of a link. `TELEGRAM` is intentionally null
 * until the channel opens; the page checks for null and simply does not render
 * that row.
 *
 * SUPPORT_EMAIL is separate from CONTROLLER.email because the two answer
 * different questions. The controller email in the privacy notice is where
 * data-subject requests land, and it has to be answerable in one calendar
 * month (GDPR Art. 12(3)). Support is where regular help goes and is answered
 * on business-days SLA.
 */

export const SUPPORT_EMAIL = 'support@lobbyhub.gg'

export const DISCORD_URL = 'https://discord.gg/bxUUtvxS7Z'

/**
 * Null until we actually operate a channel. When it opens, set this to the
 * public invite URL — the contact page will start rendering the row on the
 * next build.
 */
export const TELEGRAM_URL: string | null = null

/** Plain-English promise the contact page shows next to the form. */
export const RESPONSE_SLA = 'Within one business day.'
