package eos

import (
	"context"
	"time"
)

// SweepResult is what a walk of a deployment collected, ready for the caller
// to iterate. Sessions are already deduped by address (`ip:port`) — a session
// listed twice under different criteria (if we ever add axis slicing on top)
// is one entry here, matching the write-once rule downstream.
//
// Counts and timings are what the sweep log prints; the sessions themselves
// are what the writer acts on.
type SweepResult struct {
	Sessions []*Session
	Found    int // total rows returned across every page (before dedup)
	Distinct int // len(Sessions); split out for the log line
	Pages    int
	HTTPMs   int64
}

// Sweep walks every page of a deployment's session list and returns the
// distinct sessions. maxPages caps the walk for `--pages` on the command
// (small-batch mode for a first look); a zero or negative value takes
// everything.
//
// Ends on whichever of two conditions hits first:
//
//   - a short page (fewer sessions than `client.PageSize()`), which is what a
//     walk that has reached the end of the population looks like;
//   - `offset >= totalCount`, which is Epic's own signal that the population
//     has been exhausted — used when it is present because it is honest, and
//     backed up by the short-page rule for the regions that omit it.
//
// Failure of one page fails the whole sweep. Unlike the Steam A2S sweep
// (which can tolerate one bucket blowing up because there are dozens of
// them), EOS pagination is sequential: an offset that fails cannot be
// recovered without losing the ordering, and reporting a truncated catalog
// as complete would be the exact silent failure this class exists to avoid.
func Sweep(ctx context.Context, client *Client, dep Deployment, maxPages int) (SweepResult, error) {
	var (
		res     SweepResult
		offset  int
		seen    = make(map[string]struct{})
		started = time.Now()
	)

	for {
		if err := ctx.Err(); err != nil {
			return res, err
		}
		if maxPages > 0 && res.Pages >= maxPages {
			break
		}

		page, err := client.Filter(ctx, dep, offset)
		if err != nil {
			return res, err
		}

		res.Pages++
		res.Found += len(page.Sessions)

		if len(page.Sessions) == 0 {
			break
		}

		for _, raw := range page.Sessions {
			s := ParseSession(raw)
			if s == nil {
				continue
			}
			key := s.AddressKey()
			if _, ok := seen[key]; ok {
				continue
			}
			seen[key] = struct{}{}
			res.Sessions = append(res.Sessions, s)
		}

		offset += len(page.Sessions)

		// Both stop conditions applied — see the function comment for why.
		if page.TotalCount > 0 && offset >= page.TotalCount {
			break
		}
		if len(page.Sessions) < client.PageSize() {
			break
		}

		if pause := client.PauseMs(); pause > 0 {
			select {
			case <-ctx.Done():
				return res, ctx.Err()
			case <-time.After(time.Duration(pause) * time.Millisecond):
			}
		}
	}

	res.Distinct = len(res.Sessions)
	res.HTTPMs = time.Since(started).Milliseconds()
	return res, nil
}
