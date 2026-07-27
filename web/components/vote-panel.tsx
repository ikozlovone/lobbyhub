'use client'

import { useEffect, useState } from 'react'

/**
 * The vote form.
 *
 * Whether this visitor may vote depends on their address, so it can never come
 * from the cached page shell — it is fetched after hydration, like the live
 * player counts.
 */
export function VotePanel({ slug, apiUrl }: { slug: string; apiUrl: string }) {
  const [votes, setVotes] = useState<number | null>(null)
  const [canVote, setCanVote] = useState<boolean | null>(null)
  const [nextVoteAt, setNextVoteAt] = useState<string | null>(null)
  const [nickname, setNickname] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false

    fetch(`${apiUrl}/servers/${slug}/vote`, { cache: 'no-store' })
      .then((response) => (response.ok ? response.json() : null))
      .then((payload) => {
        if (cancelled || !payload) return
        setCanVote(payload.data.can_vote)
        setNextVoteAt(payload.data.next_vote_at)
        setVotes(payload.data.votes_total)
      })
      .catch(() => {})

    return () => {
      cancelled = true
    }
  }, [apiUrl, slug])

  async function vote() {
    setBusy(true)
    setError(null)

    try {
      const response = await fetch(`${apiUrl}/servers/${slug}/vote`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ nickname: nickname.trim() || null }),
      })
      const payload = await response.json()

      if (response.ok) {
        setCanVote(false)
        setNextVoteAt(payload.data.next_vote_at)
        setVotes(payload.data.votes_total)
      } else {
        setError(payload.message ?? 'Could not record the vote.')
        if (response.status === 429) setCanVote(false)
      }
    } catch {
      setError('Could not reach the server.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="space-y-4 rounded-lg border border-line bg-surface p-4">
      <h2 className="font-display text-sm font-bold tracking-wide uppercase">Vote</h2>

      <div>
        <label htmlFor="vote-nickname" className="block text-xs text-subtle">
          In-game name <span className="text-subtle/70">(optional — lets the server reward you)</span>
        </label>
        <input
          id="vote-nickname"
          value={nickname}
          onChange={(event) => setNickname(event.target.value)}
          maxLength={64}
          placeholder="Your nickname"
          className="mt-1 w-full rounded-md border border-line bg-bg px-3 py-2 text-sm outline-none focus:border-brand"
        />

        <button
          type="button"
          onClick={vote}
          disabled={busy || canVote === false}
          className="mt-2 w-full cursor-pointer rounded-md bg-brand px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-brand-strong disabled:cursor-not-allowed disabled:bg-surface-2 disabled:text-subtle"
        >
          {busy ? 'Voting…' : canVote === false ? 'Voted today' : 'Vote for this server'}
        </button>

        <p className="mt-2 text-xs text-subtle" aria-live="polite">
          {error ??
            (canVote === false && nextVoteAt
              ? `You can vote again after ${new Date(nextVoteAt).toLocaleDateString('en-US', {
                  month: 'short',
                  day: 'numeric',
                })}.`
              : votes !== null
                ? `${votes.toLocaleString('en-US')} vote${votes === 1 ? '' : 's'} all time · one per day`
                : 'One vote per day.')}
        </p>
      </div>
    </section>
  )
}
