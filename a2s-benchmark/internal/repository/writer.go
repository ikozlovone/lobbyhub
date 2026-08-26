package repository

import (
	"context"
	"errors"
	"log/slog"
	"sync"
	"sync/atomic"
	"time"

	"github.com/jackc/pgx/v5/pgconn"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/lobbyhub/a2s-benchmark/internal/snapshot"
)

// batchSize is how many rows go into one UPDATE ... FROM (VALUES ...).
//
// Big enough that the DB round-trip cost is amortised, small enough that a
// single batch does not lock a wide slice of one partition's index for long.
// At the 6000 req/s the sweep sustains, 500 rows flushes every ~80 ms.
const batchSize = 500

// batchTimeout is how long a flush is allowed to sit inside Postgres before
// the writer gives up on it. Well above realistic latency for a 500-row
// UPDATE — the cap is here so a wedged connection cannot stall the writer
// forever.
const batchTimeout = 10 * time.Second

// channelBuffer is how many queued rows may sit between the sweep goroutines
// and the writer before the sweep starts blocking on Enqueue. Ten batches
// worth is enough to smooth over one slow flush without letting a truly
// stuck writer inflate memory without bound.
const channelBuffer = batchSize * 10

// maxAttempts covers the first attempt plus one retry. Enough for the two
// codes that mean "your transaction lost a race" (deadlock, serialization
// failure) — a second loss on a fresh transaction is a symptom of something
// else and would only stack retries against it.
const maxAttempts = 2

// retryDelay is a small pause before the retry. Long enough for the
// contending transaction to finish, short enough not to matter to overall
// flush cadence. Uninterruptible, but bounded.
const retryDelay = 100 * time.Millisecond

// StateUpdate is one row queued for the writer.
type StateUpdate struct {
	ServerID  int64
	Snapshot  snapshot.Snapshot
	QueriedAt time.Time
}

// WriterStats is what the summary prints. All counters are cheap atomic
// loads so the caller can sample mid-run without stalling the flush loop.
type WriterStats struct {
	Enqueued     int64
	Flushed      int64 // rows attempted in a batch (whether or not the row existed)
	Written      int64 // rows the DB actually updated
	Missing      int64 // Flushed - Written — rows for servers deleted between sweep and flush
	Batches      int64
	Retries      int64 // batches that succeeded on the second attempt
	Errors       int64 // batches that failed all attempts and were dropped
	TotalBatchMs int64
}

// Writer batches state updates behind a channel and flushes them in the
// background as one UPDATE ... FROM (VALUES ...) per batchSize rows.
//
// Fail-open by design: a batch the DB rejects is counted and dropped, the
// sweep keeps running. A wedged pool cannot stall the sweep past the
// channel's buffer — Enqueue blocks then, and that is the backpressure
// signal we want (better a slower sweep than an OOM).
type Writer struct {
	pool   *pgxpool.Pool
	gameID int64

	ch chan StateUpdate
	wg sync.WaitGroup

	enqueued     atomic.Int64
	flushed      atomic.Int64
	written      atomic.Int64
	missing      atomic.Int64
	batches      atomic.Int64
	retries      atomic.Int64
	errors       atomic.Int64
	totalBatchNs atomic.Int64
}

// NewWriter starts the background flush goroutine. Close it when the sweep
// is done to drain the channel and flush the final partial batch.
func (r *Repo) NewWriter(gameID int64) *Writer {
	w := &Writer{
		pool:   r.pool,
		gameID: gameID,
		ch:     make(chan StateUpdate, channelBuffer),
	}
	w.wg.Add(1)
	go w.loop()
	return w
}

// Enqueue queues one result for eventual flush. Blocks only when the
// channel is full, which is the backpressure a wedged writer produces.
func (w *Writer) Enqueue(serverID int64, snap snapshot.Snapshot, at time.Time) {
	w.enqueued.Add(1)
	w.ch <- StateUpdate{ServerID: serverID, Snapshot: snap, QueriedAt: at}
}

// Close signals the writer to drain the channel, flush the final batch and
// exit. The passed context caps how long the drain may take — if it fires,
// pending rows are dropped and the returned error is ctx.Err().
func (w *Writer) Close(ctx context.Context) error {
	close(w.ch)
	done := make(chan struct{})
	go func() {
		w.wg.Wait()
		close(done)
	}()
	select {
	case <-done:
		return nil
	case <-ctx.Done():
		return ctx.Err()
	}
}

// Stats snapshots the counters. Safe to call at any time.
func (w *Writer) Stats() WriterStats {
	return WriterStats{
		Enqueued:     w.enqueued.Load(),
		Flushed:      w.flushed.Load(),
		Written:      w.written.Load(),
		Missing:      w.missing.Load(),
		Batches:      w.batches.Load(),
		Retries:      w.retries.Load(),
		Errors:       w.errors.Load(),
		TotalBatchMs: w.totalBatchNs.Load() / 1_000_000,
	}
}

func (w *Writer) loop() {
	defer w.wg.Done()

	buf := make([]StateUpdate, 0, batchSize)
	for u := range w.ch {
		buf = append(buf, u)
		if len(buf) >= batchSize {
			w.flush(buf)
			buf = buf[:0]
		}
	}
	if len(buf) > 0 {
		w.flush(buf)
	}
}

func (w *Writer) flush(batch []StateUpdate) {
	started := time.Now()
	defer func() {
		w.totalBatchNs.Add(time.Since(started).Nanoseconds())
		w.batches.Add(1)
	}()

	sql, args := buildUpdate(w.gameID, batch)

	var lastErr error
	for attempt := 1; attempt <= maxAttempts; attempt++ {
		ctx, cancel := context.WithTimeout(context.Background(), batchTimeout)
		tag, err := w.pool.Exec(ctx, sql, args...)
		cancel()

		if err == nil {
			rows := tag.RowsAffected()
			w.flushed.Add(int64(len(batch)))
			w.written.Add(rows)
			if int(rows) < len(batch) {
				w.missing.Add(int64(len(batch) - int(rows)))
			}
			if attempt > 1 {
				w.retries.Add(1)
			}
			return
		}

		lastErr = err

		// Anything other than a race we lost is either permanent (constraint,
		// permission, syntax) or symptomatic of infrastructure that a second
		// try would not fix. Log and drop.
		if !isRetriable(err) {
			break
		}

		// Short pause before the retry so the contending transaction has a
		// chance to finish. No further attempts after this, so no jitter or
		// exponential backoff is warranted.
		if attempt < maxAttempts {
			time.Sleep(retryDelay)
		}
	}

	// Fail-open: the run keeps going. The count shows up in the summary,
	// the message shows up in the log — a run with hot writer errors
	// deserves a look, not a hard stop.
	w.errors.Add(1)
	slog.Warn("batch dropped",
		"rows", len(batch),
		"attempts", maxAttempts,
		"err", lastErr,
	)
}

// isRetriable reports whether the error is worth retrying once.
//
// 40001 (serialization_failure) and 40P01 (deadlock_detected) are the two
// Postgres codes that mean "your transaction lost a race — try again".
// Postgres has already rolled our side back, so a fresh Exec on the same
// SQL is clean. Any other class of error would fail identically on retry.
func isRetriable(err error) bool {
	var pgErr *pgconn.PgError
	if !errors.As(err, &pgErr) {
		return false
	}
	return pgErr.Code == "40001" || pgErr.Code == "40P01"
}
