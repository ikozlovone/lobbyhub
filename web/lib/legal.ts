/**
 * Who is legally answerable for this site.
 *
 * GDPR Art. 13(1)(a) makes the controller's identity and a contact address
 * mandatory in the privacy notice — a notice without them is not a notice. They
 * are configuration rather than constants because the person running a fork is
 * not the person running lobbyhub.gg, and a hard-coded name is one a fork
 * silently keeps publishing.
 *
 * Unset values fall back to visible placeholders, and `LEGAL_UNSET` is true
 * whenever any remain, so the legal pages can say so out loud instead of
 * quietly promising somebody an answer at an address that does not exist.
 */

const PLACEHOLDER = {
  name: '[controller name — set NEXT_PUBLIC_CONTROLLER_NAME]',
  address: '[postal address — set NEXT_PUBLIC_CONTROLLER_ADDRESS]',
  email: 'hello@example.com',
  hosting: '[country — set NEXT_PUBLIC_HOSTING_COUNTRY]',
}

export const CONTROLLER = {
  /** A natural person's full name, or the registered name of the company. */
  name: process.env.NEXT_PUBLIC_CONTROLLER_NAME || PLACEHOLDER.name,
  /**
   * A postal address somebody can actually write to. Required: "contact
   * details" in Art. 13 is not satisfied by an email address alone.
   */
  address: process.env.NEXT_PUBLIC_CONTROLLER_ADDRESS || PLACEHOLDER.address,
  email: process.env.NEXT_PUBLIC_CONTACT_EMAIL || PLACEHOLDER.email,
  /** Where the machine holding the database physically sits — it decides whether there is a transfer to disclose. */
  hosting: process.env.NEXT_PUBLIC_HOSTING_COUNTRY || PLACEHOLDER.hosting,
}

export const LEGAL_UNSET =
  CONTROLLER.name === PLACEHOLDER.name ||
  CONTROLLER.address === PLACEHOLDER.address ||
  CONTROLLER.email === PLACEHOLDER.email ||
  CONTROLLER.hosting === PLACEHOLDER.hosting

/** Changed only when the text changes in a way a reader would want to know about. */
export const TERMS_UPDATED = '2 August 2026'
export const PRIVACY_UPDATED = '2 August 2026'
