/**
 * Client for the account endpoints.
 *
 * Called from the browser, never from a cached shell: who is signed in is the
 * one thing on this site that must not be prerendered.
 *
 * Failures come back as values for the same reason the submission form's do —
 * "that code is wrong" is an outcome the dialog renders, not an exception.
 */

export type AuthUser = {
  id: number
  name: string
  email: string | null
  avatar: string | null
  providers?: string[]
  joined_at: string | null
}

export type AuthProviderInfo = {
  key: 'steam' | 'discord' | 'google'
  label: string
  url: string
  /** False when this deployment has no credentials for it — the button explains itself. */
  available: boolean
}

export type Result<T> = ({ ok: true } & T) | { ok: false; error: string }

async function readError(response: Response, fallback: string): Promise<string> {
  const payload = await response.json().catch(() => null)

  // Laravel puts the sentence worth showing under the field it belongs to.
  const fieldError = Object.values(payload?.errors ?? {})[0]

  return (Array.isArray(fieldError) ? fieldError[0] : null) ?? payload?.message ?? fallback
}

export async function requestCode(
  apiUrl: string,
  email: string,
): Promise<Result<{ resendIn: number; expiresIn: number }>> {
  try {
    const response = await fetch(`${apiUrl}/auth/email`, {
      method: 'POST',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ email }),
    })

    if (!response.ok) {
      return { ok: false, error: await readError(response, 'Could not send the code.') }
    }

    const { data } = await response.json()

    return { ok: true, resendIn: data.resend_in, expiresIn: data.expires_in }
  } catch {
    return { ok: false, error: 'Could not reach LobbyHub. Check your connection.' }
  }
}

export async function verifyCode(
  apiUrl: string,
  email: string,
  code: string,
): Promise<Result<{ token: string; user: AuthUser }>> {
  try {
    const response = await fetch(`${apiUrl}/auth/email/verify`, {
      method: 'POST',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ email, code }),
    })

    if (!response.ok) {
      return { ok: false, error: await readError(response, 'That code did not work.') }
    }

    const { data } = await response.json()

    return { ok: true, token: data.token, user: data.user }
  } catch {
    return { ok: false, error: 'Could not reach LobbyHub. Check your connection.' }
  }
}

/** Resolves a stored token back into an account. Null means the token is gone or expired. */
export async function fetchMe(apiUrl: string, token: string): Promise<AuthUser | null> {
  try {
    const response = await fetch(`${apiUrl}/auth/me`, {
      cache: 'no-store',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    })

    return response.ok ? (await response.json()).data : null
  } catch {
    return null
  }
}

export async function signOut(apiUrl: string, token: string): Promise<void> {
  try {
    await fetch(`${apiUrl}/auth/logout`, {
      method: 'POST',
      cache: 'no-store',
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    })
  } catch {
    // The token is dropped locally either way: a visitor who clicked sign out
    // is signed out, whatever the network thinks.
  }
}

export async function fetchProviders(apiUrl: string): Promise<AuthProviderInfo[]> {
  const response = await fetch(`${apiUrl}/auth/providers`, { headers: { Accept: 'application/json' } })

  if (!response.ok) {
    // Thrown rather than returned empty, unlike everything else in this file:
    // this one answer gets cached, and an empty list is indistinguishable from
    // a deployment that genuinely has no providers. See getAuthProviders.
    throw new Error(`Sign-in providers unavailable (${response.status})`)
  }

  return (await response.json()).data
}
