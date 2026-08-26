package chstats

import (
	"context"
	"log/slog"
	"sync"
	"time"
)

// point is one row queued for the INSERT — kept internal so callers can
// only push via Enqueue, which validates and captures the sweep-wide ts.
type point struct {
	serverID uint64
	players  uint16
}

// Stats is what main prints after a sweep.
type Stats struct {
	Enqueued int
	Written  int
	Errors   int
	FlushMs  int64
}

// Writer collects one game's online-player samples during a sweep and
// flushes them as one batched INSERT at the end. Fail-open: a rejected
// flush is counted and dropped, no error is returned up.
//
// One Writer per game per sweep. Cheap to create (no allocation-heavy
// work in constructor) and Flush is what actually spends the network.
type Writer struct {
	client *Client
	gameID uint32
	ts     time.Time

	mu     sync.Mutex
	points []point
	stats  Stats
}

// Enqueue adds one point for the sweep. Called from the sweep's per-server
// goroutines — a mutex guards the slice. Contention is negligible next to
// the network work each goroutine is doing.
//
// serverID > 0 is enforced upstream (a zero id is a caller bug, not our
// job to filter here).
func (w *Writer) Enqueue(serverID uint64, players uint16) {
	w.mu.Lock()
	w.points = append(w.points, point{serverID: serverID, players: players})
	w.stats.Enqueued++
	w.mu.Unlock()
}

// Flush pushes every collected row as one INSERT and returns the CH
// error verbatim. Called after the sweep goroutines have all finished,
// so no mutex contention: we take the slice, release the lock, run the
// insert.
//
// After Flush the writer holds no rows; a second call is a no-op.
func (w *Writer) Flush(ctx context.Context) error {
	if w == nil {
		return nil
	}

	w.mu.Lock()
	batch := w.points
	w.points = nil
	w.mu.Unlock()

	if len(batch) == 0 {
		return nil
	}

	started := time.Now()
	defer func() {
		w.mu.Lock()
		w.stats.FlushMs += time.Since(started).Milliseconds()
		w.mu.Unlock()
	}()

	// PrepareBatch on the raw table; every row is (ts, game_id, server_id,
	// players_online) in that column order — must match the CREATE TABLE.
	insertCtx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()

	prep, err := w.client.conn.PrepareBatch(insertCtx,
		"INSERT INTO server_players_raw (ts, game_id, server_id, players_online)")
	if err != nil {
		w.recordError()
		slog.Warn("chstats prepare failed", "game_id", w.gameID, "err", err)
		return err
	}

	for _, p := range batch {
		if err := prep.Append(w.ts, w.gameID, p.serverID, p.players); err != nil {
			w.recordError()
			slog.Warn("chstats append failed", "game_id", w.gameID, "err", err)
			return err
		}
	}

	if err := prep.Send(); err != nil {
		w.recordError()
		slog.Warn("chstats send failed", "game_id", w.gameID, "rows", len(batch), "err", err)
		return err
	}

	w.mu.Lock()
	w.stats.Written += len(batch)
	w.mu.Unlock()
	return nil
}

func (w *Writer) recordError() {
	w.mu.Lock()
	w.stats.Errors++
	w.mu.Unlock()
}

// Snapshot returns the current stats. Safe to call at any time.
func (w *Writer) Snapshot() Stats {
	if w == nil {
		return Stats{}
	}
	w.mu.Lock()
	defer w.mu.Unlock()
	return w.stats
}
