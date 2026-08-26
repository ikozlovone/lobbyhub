package benchmark

import (
	"context"
	"fmt"
	"log/slog"
	"math/rand"
	"runtime"
	"sync"
	"time"

	"github.com/lobbyhub/a2s-benchmark/internal/a2s"
	"github.com/lobbyhub/a2s-benchmark/internal/chstats"
	"github.com/lobbyhub/a2s-benchmark/internal/metrics"
	"github.com/lobbyhub/a2s-benchmark/internal/repository"
	"github.com/lobbyhub/a2s-benchmark/internal/slp"
	"github.com/lobbyhub/a2s-benchmark/internal/snapshot"
)

// Config is everything the runner needs. It mirrors the CLI flags — see
// cmd/a2s-benchmark for the wiring.
type Config struct {
	GameSlug    string
	GameName    string
	Concurrency int
	Timeout     time.Duration
	Retries     int
	RatePerSec  int
	Shuffle     bool
	Seed        int64
	Limit       int

	// Writer is optional. When non-nil, every result — online or offline —
	// is enqueued for a batched UPDATE against server_states. The runner
	// closes it after the sweep, blocking until the last batch flushes.
	Writer *repository.Writer

	// Stats is optional. When non-nil, every Responded result (only those)
	// is queued for a ClickHouse INSERT — one row per server per sweep,
	// timestamp truncated to the 10-minute boundary the Writer captured at
	// construction. Flushed once after the sweep completes.
	Stats *chstats.Writer
}

// Run is the whole benchmark: shuffle, dispatch, tick, sum up, write JSON.
//
// Blocks until every queued server has been probed or the context is cancelled.
// The caller handles signals — a SIGINT sets ctx.Err() and the loops fall out.
func Run(ctx context.Context, cfg Config, servers []repository.Server, loaded repository.LoadCounts) (*Report, error) {
	if cfg.Shuffle {
		rng := rand.New(rand.NewSource(cfg.Seed))
		rng.Shuffle(len(servers), func(i, j int) { servers[i], servers[j] = servers[j], servers[i] })
	}
	if cfg.Limit > 0 && cfg.Limit < len(servers) {
		servers = servers[:cfg.Limit]
	}

	fmt.Printf("Game: %s\n", nameOrSlug(cfg))
	fmt.Printf("Servers loaded: %d\n", loaded.Total)
	fmt.Printf("Valid endpoints: %d\n", loaded.Valid)
	if loaded.MissingQueryPort > 0 {
		fmt.Printf("Missing query_port: %d\n", loaded.MissingQueryPort)
	}
	if loaded.MissingIP > 0 {
		fmt.Printf("Missing ip_address: %d\n", loaded.MissingIP)
	}
	if loaded.UnknownProtocol > 0 {
		fmt.Printf("Unknown protocol (skipped): %d\n", loaded.UnknownProtocol)
	}
	fmt.Printf("Concurrency: %d, timeout: %s, retries: %d", cfg.Concurrency, cfg.Timeout, cfg.Retries)
	if cfg.RatePerSec > 0 {
		fmt.Printf(", rate: %d/s", cfg.RatePerSec)
	}
	fmt.Println()
	if cfg.Limit > 0 {
		fmt.Printf("Limit: %d (of %d)\n", cfg.Limit, loaded.Valid)
	}
	fmt.Println("Starting sweep...")

	counters := NewCounters()
	hist := metrics.New(len(servers))
	bound := NewBound(cfg.Concurrency)
	rate := NewRateLimiter(cfg.RatePerSec)
	defer rate.Stop()

	// Peak goroutine tracker — reads runtime.NumGoroutine from the ticker.
	var peakGoroutines Peak
	// Peak memory tracker — reads runtime.MemStats.HeapAlloc periodically.
	var peakMemBytes Peak

	tickerDone := make(chan struct{})
	go progressTicker(ctx, counters, hist, &peakGoroutines, &peakMemBytes, tickerDone)

	var wg sync.WaitGroup
	dispatched := 0

	for i := range servers {
		if err := ctx.Err(); err != nil {
			break
		}
		if err := bound.Acquire(ctx); err != nil {
			break
		}
		if err := rate.Wait(ctx); err != nil {
			bound.Release()
			break
		}

		server := servers[i]
		dispatched++
		wg.Add(1)
		counters.InFlight.Add(1)

		go func() {
			defer wg.Done()
			defer bound.Release()
			defer counters.InFlight.Add(-1)

			result := queryWithRetry(ctx, server, cfg.Timeout, cfg.Retries)
			counters.Record(result, hist)

			// Enqueue happens on the sweep goroutine so backpressure from a
			// slow writer throttles the network, rather than being absorbed
			// by an unbounded queue.
			//
			// UTC because the state columns are `timestamp` (no timezone)
			// and Laravel writes them in UTC (config('app.timezone')). A
			// local-time write would be off by the host's offset and read
			// back three hours in the future on an MSK box.
			if cfg.Writer != nil {
				cfg.Writer.Enqueue(server.ID, result, time.Now().UTC())
			}

			// Stats: only rows we actually measured a player count for.
			// Offline outcomes (timeout / network error / …) contribute
			// nothing to a per-server player-history graph and would just
			// inflate ClickHouse with zeros.
			if cfg.Stats != nil && result.Outcome == snapshot.OutcomeResponded && result.Info != nil {
				cfg.Stats.Enqueue(uint64(server.ID), uint16(result.Info.PlayersOnline))
			}
		}()
	}

	wg.Wait()
	close(tickerDone)

	// Drain the writer before printing the summary so the numbers below
	// include the final batch. A generous cap — enough for a stuck DB to
	// clear a full channel — with the caller's ctx still respected.
	if cfg.Writer != nil {
		closeCtx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
		if err := cfg.Writer.Close(closeCtx); err != nil {
			slog.Warn("writer close failed", "game", cfg.GameSlug, "err", err)
		}
		cancel()
	}

	// Flush stats after the state writer — reader-friendly ordering: if
	// both fail, the message from the more critical (state) writer shows
	// up first. Fail-open, counted in the summary either way.
	if cfg.Stats != nil {
		flushCtx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		if err := cfg.Stats.Flush(flushCtx); err != nil {
			slog.Warn("stats flush failed", "game", cfg.GameSlug, "err", err)
		}
		cancel()
	}

	elapsed := time.Since(counters.StartedAt)
	sorted, buckets := hist.Snapshot()

	report := buildReport(cfg, loaded, dispatched, elapsed, counters, sorted, buckets, peakGoroutines.Load(), peakMemBytes.Load())
	if cfg.Writer != nil {
		s := cfg.Writer.Stats()
		report.Writer = &s
	}
	if cfg.Stats != nil {
		s := cfg.Stats.Snapshot()
		report.Stats = &s
	}
	report.print()

	return report, nil
}

// queryOne picks the right client for the server's protocol. Adding a new
// protocol is a case here plus a new package under internal/.
func queryOne(ctx context.Context, server repository.Server, timeout time.Duration) snapshot.Snapshot {
	switch server.Protocol {
	case repository.ProtocolSource:
		return a2s.Query(ctx, server.Endpoint, timeout)
	case repository.ProtocolMinecraft:
		return slp.Query(ctx, server.Endpoint, timeout)
	}
	// LoadForGame refuses to hand out unknown-protocol servers, so this is
	// a bug rather than a runtime condition. Report it as a network error
	// so the counter reflects the failure without crashing the sweep.
	return snapshot.Snapshot{
		Outcome: snapshot.OutcomeNetworkError,
		Err:     fmt.Errorf("unsupported protocol: %s", server.Protocol),
	}
}

func queryWithRetry(ctx context.Context, server repository.Server, timeout time.Duration, retries int) snapshot.Snapshot {
	var last snapshot.Snapshot
	for attempt := 0; attempt <= retries; attempt++ {
		if ctx.Err() != nil {
			return snapshot.Snapshot{Outcome: snapshot.OutcomeNetworkError, Err: ctx.Err()}
		}
		last = queryOne(ctx, server, timeout)
		if last.Outcome == snapshot.OutcomeResponded {
			return last
		}
		// Retry only on soft failures — a malformed response is a bug the
		// server is not going to fix by being asked twice.
		if last.Outcome != snapshot.OutcomeTimeout && last.Outcome != snapshot.OutcomeNetworkError {
			return last
		}
	}
	return last
}

func progressTicker(ctx context.Context, c *Counters, hist *metrics.Histogram, peakG, peakM *Peak, done <-chan struct{}) {
	t := time.NewTicker(time.Second)
	defer t.Stop()
	var mem runtime.MemStats
	for {
		select {
		case <-t.C:
			peakG.Update(int64(runtime.NumGoroutine()))
			runtime.ReadMemStats(&mem)
			peakM.Update(int64(mem.HeapAlloc))

			sorted, _ := hist.Snapshot()
			p50, p95, p99 := metrics.Percentiles(sorted)

			elapsed := time.Since(c.StartedAt)
			processed := c.Processed.Load()
			rate := float64(processed) / elapsed.Seconds()

			fmt.Printf("[%s] processed %d online %d timeout %d errors %d in-flight %d rate %.0f/s p50=%s p95=%s p99=%s\n",
				formatElapsed(elapsed),
				processed,
				c.Responded.Load(),
				c.Timeout.Load(),
				c.NetworkError.Load()+c.ProtocolError.Load()+c.Malformed.Load(),
				c.InFlight.Load(),
				rate,
				p50, p95, p99,
			)
		case <-done:
			return
		case <-ctx.Done():
			return
		}
	}
}

func nameOrSlug(cfg Config) string {
	if cfg.GameName != "" {
		return cfg.GameName
	}
	return cfg.GameSlug
}

// Report is what gets printed at the end of a run. It used to be dumped
// as JSON too — that path was removed once the writer was doing the real
// work, so the fields are plain Go and no longer carry `json:` tags.
type Report struct {
	Game                    string
	GameName                string
	Loaded                  int
	Attempted               int
	MissingQueryPort        int
	MissingIP               int
	UnknownProtocol         int
	Concurrency             int
	TimeoutMs               int64
	Retries                 int
	RatePerSec              int
	ElapsedMs               int64
	Responded               int64
	PlayersOnline           int64
	Timeouts                int64
	NetworkErrors           int64
	ProtocolErrors          int64
	MalformedResponses      int64
	PacketsSent             int64
	AvgLatencyMs            int64
	LatencyP50Ms            int64
	LatencyP95Ms            int64
	LatencyP99Ms            int64
	LatencyBuckets          map[string]int64
	AverageServersPerSecond float64
	PeakMemBytes            int64
	PeakGoroutines          int64
	StartedAt               time.Time
	FinishedAt              time.Time

	Writer *repository.WriterStats
	Stats  *chstats.Stats
}

func buildReport(cfg Config, loaded repository.LoadCounts, attempted int, elapsed time.Duration,
	c *Counters, sorted []time.Duration, buckets [6]int64,
	peakGoroutines, peakMem int64) *Report {

	p50, p95, p99 := metrics.Percentiles(sorted)
	avg := metrics.Avg(sorted)

	bucketMap := map[string]int64{}
	for i, name := range metrics.BucketNames {
		bucketMap[name] = buckets[i]
	}

	seconds := elapsed.Seconds()
	var rate float64
	if seconds > 0 {
		rate = float64(c.Processed.Load()) / seconds
	}

	return &Report{
		Game:                    cfg.GameSlug,
		GameName:                cfg.GameName,
		Loaded:                  loaded.Total,
		Attempted:               attempted,
		MissingQueryPort:        loaded.MissingQueryPort,
		MissingIP:               loaded.MissingIP,
		UnknownProtocol:         loaded.UnknownProtocol,
		Concurrency:             cfg.Concurrency,
		TimeoutMs:               cfg.Timeout.Milliseconds(),
		Retries:                 cfg.Retries,
		RatePerSec:              cfg.RatePerSec,
		ElapsedMs:               elapsed.Milliseconds(),
		Responded:               c.Responded.Load(),
		PlayersOnline:           c.PlayersOnline.Load(),
		Timeouts:                c.Timeout.Load(),
		NetworkErrors:           c.NetworkError.Load(),
		ProtocolErrors:          c.ProtocolError.Load(),
		MalformedResponses:      c.Malformed.Load(),
		PacketsSent:             c.PacketsSent.Load(),
		AvgLatencyMs:            avg.Milliseconds(),
		LatencyP50Ms:            p50.Milliseconds(),
		LatencyP95Ms:            p95.Milliseconds(),
		LatencyP99Ms:            p99.Milliseconds(),
		LatencyBuckets:          bucketMap,
		AverageServersPerSecond: rate,
		PeakMemBytes:            peakMem,
		PeakGoroutines:          peakGoroutines,
		StartedAt:               c.StartedAt,
		FinishedAt:              c.StartedAt.Add(elapsed),
	}
}

func (r *Report) print() {
	fmt.Println()
	fmt.Println("A2S benchmark finished")
	fmt.Println()
	if r.GameName != "" {
		fmt.Printf("Game: %s\n", r.GameName)
	} else {
		fmt.Printf("Game: %s\n", r.Game)
	}
	fmt.Println()
	fmt.Println("Servers:")
	fmt.Printf("  loaded:              %d\n", r.Loaded)
	fmt.Printf("  attempted:           %d\n", r.Attempted)
	if r.MissingQueryPort > 0 {
		fmt.Printf("  missing query_port:  %d\n", r.MissingQueryPort)
	}
	if r.MissingIP > 0 {
		fmt.Printf("  missing ip_address:  %d\n", r.MissingIP)
	}
	if r.UnknownProtocol > 0 {
		fmt.Printf("  unknown protocol:    %d\n", r.UnknownProtocol)
	}
	fmt.Println()
	fmt.Println("Results:")
	fmt.Printf("  responded:           %d\n", r.Responded)
	fmt.Printf("  players online:      %d\n", r.PlayersOnline)
	fmt.Printf("  timeout:             %d\n", r.Timeouts)
	fmt.Printf("  network errors:      %d\n", r.NetworkErrors)
	if r.ProtocolErrors > 0 {
		fmt.Printf("  protocol errors:     %d\n", r.ProtocolErrors)
	}
	if r.MalformedResponses > 0 {
		fmt.Printf("  malformed responses: %d\n", r.MalformedResponses)
	}
	if r.Attempted > 0 {
		rate := float64(r.Responded) / float64(r.Attempted) * 100
		fmt.Println()
		fmt.Println("Response rate:")
		fmt.Printf("  %.2f%%\n", rate)
	}
	fmt.Println()
	fmt.Println("Timing:")
	fmt.Printf("  total:               %s (%d ms)\n", formatElapsed(time.Duration(r.ElapsedMs)*time.Millisecond), r.ElapsedMs)
	fmt.Printf("  avg latency:         %d ms\n", r.AvgLatencyMs)
	fmt.Printf("  p50 latency:         %d ms\n", r.LatencyP50Ms)
	fmt.Printf("  p95 latency:         %d ms\n", r.LatencyP95Ms)
	fmt.Printf("  p99 latency:         %d ms\n", r.LatencyP99Ms)
	fmt.Println()
	fmt.Println("Performance:")
	fmt.Printf("  average:             %.0f servers/sec\n", r.AverageServersPerSecond)
	fmt.Printf("  packets sent:        %d\n", r.PacketsSent)
	fmt.Println()
	fmt.Println("Latency distribution:")
	for _, name := range metrics.BucketNames {
		fmt.Printf("  %-12s %d\n", name, r.LatencyBuckets[name])
	}
	fmt.Println()
	fmt.Println("System:")
	fmt.Printf("  peak memory:         %.1f MB\n", float64(r.PeakMemBytes)/(1024*1024))
	fmt.Printf("  peak goroutines:     %d\n", r.PeakGoroutines)
	fmt.Println()
	if r.Writer != nil {
		w := r.Writer
		avgMs := int64(0)
		if w.Batches > 0 {
			avgMs = w.TotalBatchMs / w.Batches
		}
		fmt.Println("Database writes:")
		fmt.Printf("  enqueued:            %d\n", w.Enqueued)
		fmt.Printf("  written:             %d\n", w.Written)
		fmt.Printf("  missing:             %d\n", w.Missing)
		fmt.Printf("  batches:             %d\n", w.Batches)
		fmt.Printf("  batch retries:       %d\n", w.Retries)
		fmt.Printf("  batch errors:        %d\n", w.Errors)
		fmt.Printf("  avg batch:           %d ms\n", avgMs)
		fmt.Println()
	}
	if r.Stats != nil {
		s := r.Stats
		fmt.Println("ClickHouse stats:")
		fmt.Printf("  enqueued:            %d\n", s.Enqueued)
		fmt.Printf("  written:             %d\n", s.Written)
		fmt.Printf("  errors:              %d\n", s.Errors)
		fmt.Printf("  flush time:          %d ms\n", s.FlushMs)
		fmt.Println()
	}
	fmt.Println("Configuration:")
	fmt.Printf("  concurrency:         %d\n", r.Concurrency)
	fmt.Printf("  timeout:             %d ms\n", r.TimeoutMs)
	fmt.Printf("  retries:             %d\n", r.Retries)
	fmt.Printf("  rate limit:          %d\n", r.RatePerSec)
	fmt.Println()
	fmt.Printf("TOTAL WALL CLOCK TIME: %s\n", formatElapsed(time.Duration(r.ElapsedMs)*time.Millisecond))
}

func formatElapsed(d time.Duration) string {
	d = d.Round(time.Second)
	h := int(d.Hours())
	m := int(d.Minutes()) % 60
	s := int(d.Seconds()) % 60
	if h > 0 {
		return fmt.Sprintf("%02d:%02d:%02d", h, m, s)
	}
	return fmt.Sprintf("%02d:%02d", m, s)
}
