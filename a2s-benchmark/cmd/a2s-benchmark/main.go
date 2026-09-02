// a2s-benchmark: sweep every server of one game (or every supported game)
// and report how long it took.
//
// Read-only by default; --write flips the sweep into collector mode and pushes
// each result to `server_states` as a batched UPDATE. See a2s-benchmark.txt
// for what this exists to answer.
package main

import (
	"context"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"

	"github.com/lobbyhub/a2s-benchmark/internal/applog"
	"github.com/lobbyhub/a2s-benchmark/internal/benchmark"
	"github.com/lobbyhub/a2s-benchmark/internal/chstats"
	"github.com/lobbyhub/a2s-benchmark/internal/envfile"
	"github.com/lobbyhub/a2s-benchmark/internal/repository"
)

func main() {
	os.Exit(run())
}

func run() int {
	var (
		game        = flag.String("game", "", "Game slug to sweep; mutually exclusive with --all-games")
		allGames    = flag.Bool("all-games", false, "Sweep every active game with a supported protocol, sequentially")
		concurrency = flag.Int("concurrency", 500, "Max in-flight requests per game sweep")
		timeoutStr  = flag.String("timeout", "1s", "Per-server deadline including any protocol round trips")
		retries     = flag.Int("retries", 0, "Extra attempts after a timeout or network error")
		rate        = flag.Int("rate", 0, "Requests-per-second ceiling (0 = only bounded by concurrency)")
		limit       = flag.Int("limit", 0, "Cap on servers to attempt per game (0 = all)")
		shuffle     = flag.Bool("shuffle", true, "Shuffle the server list before sweeping")
		seed        = flag.Int64("seed", time.Now().UnixNano(), "Shuffle seed for reproducibility")
		envPath     = flag.String("env", ".env", "Path to a Laravel-style .env; missing file is silently skipped")
		dsn         = flag.String("dsn", "", "Postgres DSN (overrides A2S_BENCHMARK_DSN and DB_* env vars)")
		write       = flag.Bool("write", false, "Push every result to server_states as a batched UPDATE (requires UPDATE on server_states)")
		logLevel    = flag.String("log-level", "info", "slog level: debug|info|warn|error")
		logFile     = flag.String("log-file", "", "Also write structured logs to this rotating file (empty = stderr only)")
	)
	flag.Usage = usage
	flag.Parse()

	// Logger first so anything that follows uses the configured level and
	// file sink. Closing at end drains the rotating writer's last write.
	logCloser := applog.Setup(applog.Config{Level: *logLevel, File: *logFile})
	defer logCloser.Close()

	// Load .env before resolving DSN so DB_* vars are visible. Existing env
	// wins over the file; loud on parse errors, silent on missing file.
	if loaded, err := envfile.Load(*envPath); err != nil {
		fmt.Fprintf(os.Stderr, "env: %v\n", err)
		return 2
	} else if loaded {
		fmt.Fprintf(os.Stderr, "env: loaded %s\n", *envPath)
	}

	// --game and --all-games mean different things; picking one and running
	// the other would silently ignore an operator's intent.
	if strings.TrimSpace(*game) == "" && !*allGames {
		fmt.Fprintln(os.Stderr, "either --game=<slug> or --all-games is required")
		flag.Usage()
		return 2
	}
	if strings.TrimSpace(*game) != "" && *allGames {
		fmt.Fprintln(os.Stderr, "--game and --all-games are mutually exclusive")
		return 2
	}

	resolvedDSN := resolveDSN(*dsn)
	if resolvedDSN == "" {
		fmt.Fprintln(os.Stderr, "postgres DSN not set: pass --dsn, export A2S_BENCHMARK_DSN, or set DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD in .env")
		return 2
	}

	timeout, err := time.ParseDuration(*timeoutStr)
	if err != nil {
		fmt.Fprintf(os.Stderr, "bad --timeout: %v\n", err)
		return 2
	}

	// Ctrl+C stops both the queue dispatch and the in-flight UDP reads —
	// the latter through the deadline the client already sets on the socket
	// plus a manual check in the retry loop.
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	repo, err := repository.Open(ctx, resolvedDSN)
	if err != nil {
		fmt.Fprintf(os.Stderr, "open db: %v\n", err)
		return 1
	}
	defer repo.Close()

	// ClickHouse client is optional. When --write is on and CH_HOST is
	// set, we open one connection for the whole run and hand out per-game
	// writers on top. When either is missing, stats writes are silently a
	// no-op — the state sweep does not depend on ClickHouse.
	var chClient *chstats.Client
	if *write {
		if opts, ok := chstats.FromEnv(); ok {
			c, err := chstats.Open(ctx, opts)
			if err != nil {
				slog.Warn("ClickHouse unavailable, stats disabled", "err", err)
			} else {
				chClient = c
				defer chClient.Close()
			}
		}
	}

	baseCfg := benchmark.Config{
		Concurrency: *concurrency,
		Timeout:     timeout,
		Retries:     *retries,
		RatePerSec:  *rate,
		Shuffle:     *shuffle,
		Seed:        *seed,
		Limit:       *limit,
	}

	if *allGames {
		return runAllGames(ctx, repo, chClient, baseCfg, *write)
	}
	return runOneGame(ctx, repo, chClient, *game, baseCfg, *write)
}

// resolveDSN picks the DSN in the priority order the flag help promises:
// explicit --dsn, then A2S_BENCHMARK_DSN, then a DSN assembled from the
// Laravel DB_* vars. Empty return means no DSN was configured anywhere.
func resolveDSN(flagValue string) string {
	if strings.TrimSpace(flagValue) != "" {
		return flagValue
	}
	if v := strings.TrimSpace(os.Getenv("A2S_BENCHMARK_DSN")); v != "" {
		return v
	}
	return envfile.BuildDSN()
}

func runOneGame(ctx context.Context, repo *repository.Repo, ch *chstats.Client, slug string, baseCfg benchmark.Config, write bool) int {
	g, err := repo.LookupGame(ctx, slug)
	if err != nil {
		fmt.Fprintf(os.Stderr, "load game: %v\n", err)
		return 1
	}

	var chWriter *chstats.Writer
	if write && ch != nil {
		chWriter = ch.NewSweepWriter()
	}

	if _, err := sweepGame(ctx, repo, chWriter, g, baseCfg, write); err != nil {
		fmt.Fprintf(os.Stderr, "run: %v\n", err)
		return 1
	}

	// One INSERT per run — the shared writer holds every row we enqueued
	// for this one game and pushes them in a single batch. Fail-open.
	if chWriter != nil {
		flushCtx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		if err := chWriter.Flush(flushCtx); err != nil {
			slog.Warn("chstats flush failed", "err", err)
		}
		cancel()
	}
	return 0
}

// runAllGames sweeps every supported game one after another. Sequential
// rather than parallel: each partition sees a clean writer with a fresh
// batch history, resource use stays predictable, and pgbouncer is never
// asked to hold N game-writer pools at once. A game failing does not
// stop the loop — the exit code reflects whether anyone failed.
func runAllGames(ctx context.Context, repo *repository.Repo, ch *chstats.Client, baseCfg benchmark.Config, write bool) int {
	games, err := repo.ListGames(ctx)
	if err != nil {
		fmt.Fprintf(os.Stderr, "list games: %v\n", err)
		return 1
	}
	if len(games) == 0 {
		fmt.Fprintln(os.Stderr, "no games with a supported protocol")
		return 1
	}

	fmt.Printf("Sweeping %d games sequentially...\n\n", len(games))
	slog.Info("all-games start", "games", len(games))

	// One shared ClickHouse writer for the whole run. Every game's rows
	// go into the same buffer; one INSERT lands them all after the loop.
	var chWriter *chstats.Writer
	if write && ch != nil {
		chWriter = ch.NewSweepWriter()
	}

	started := time.Now()
	var (
		ok, failed       int
		totalResponded   int64
		totalPlayers     int64
		totalDBWritten   int64
		totalDBErrors    int64
		totalCHEnqueued  int
	)

	for _, g := range games {
		if ctx.Err() != nil {
			fmt.Fprintln(os.Stderr, "interrupted — stopping loop")
			slog.Warn("interrupted", "processed", ok, "failed", failed)
			break
		}
		fmt.Printf("========== %s (%s) ==========\n", g.Slug, g.Protocol)
		res, err := sweepGame(ctx, repo, chWriter, g, baseCfg, write)
		if err != nil {
			fmt.Fprintf(os.Stderr, "%s: %v\n", g.Slug, err)
			slog.Error("sweep failed", "game", g.Slug, "err", err)
			failed++
			continue
		}
		ok++
		totalResponded += res.Responded
		totalPlayers += res.PlayersOnline
		totalDBWritten += res.DBWritten
		totalDBErrors += res.DBErrors
		totalCHEnqueued += res.CHEnqueued
	}

	// One INSERT for the whole run. Snapshot after so the summary reflects
	// what really made it into ClickHouse — Enqueued from the loop counts
	// rows fed to the buffer, Written comes from the actual network write.
	var (
		totalCHWritten int
		totalCHErrors  int
	)
	if chWriter != nil {
		flushCtx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		if err := chWriter.Flush(flushCtx); err != nil {
			slog.Warn("chstats flush failed", "err", err)
		}
		cancel()
		s := chWriter.Snapshot()
		totalCHWritten = s.Written
		totalCHErrors = s.Errors
	}

	totalWall := time.Since(started)

	fmt.Println()
	fmt.Println("========== All games ==========")
	fmt.Printf("processed:  %d\n", ok)
	fmt.Printf("failed:     %d\n", failed)
	fmt.Printf("wall clock: %s\n", totalWall.Round(time.Second))

	// One machine-readable "done" event for the whole run — this is what a
	// cron-driven install monitors to answer "did the last cycle fit in
	// its window, and did everything write cleanly?".
	slog.Info("all-games done",
		"total_wall_ms", totalWall.Milliseconds(),
		"processed", ok,
		"failed", failed,
		"total_responded", totalResponded,
		"total_players", totalPlayers,
		"total_db_written", totalDBWritten,
		"total_db_errors", totalDBErrors,
		"total_ch_enqueued", totalCHEnqueued,
		"total_ch_written", totalCHWritten,
		"total_ch_errors", totalCHErrors,
	)

	if failed > 0 {
		return 1
	}
	return 0
}

// sweepResult is what runAllGames aggregates for its final "all games done"
// event — the per-game numbers a monitor cares about, without the noise of
// per-server outcomes. sweepGame returns one of these plus any hard error.
//
// CHEnqueued is the per-game contribution to the shared ClickHouse writer's
// buffer. The actual `total_ch_written` is only known after the run-wide
// Flush, so a per-game "how many rows made it to CH" is not a thing —
// this is the closest analogue: how many rows this game handed off.
type sweepResult struct {
	Slug          string
	ElapsedMs     int64 // full wall clock, including counters + Redis DEL
	SweepMs       int64 // sweep + writes only, as measured inside benchmark.Run
	Responded     int64
	PlayersOnline int64
	Timeouts      int64
	NetworkErrors int64
	DBWritten     int64
	DBErrors      int64
	DBRetries     int64
	CHEnqueued    int
}

func sweepGame(ctx context.Context, repo *repository.Repo, chWriter *chstats.Writer, g repository.GameInfo, baseCfg benchmark.Config, write bool) (sweepResult, error) {
	// EOS games do not use the per-server UDP path at all — they are one
	// paginated HTTP pull matched against the address map. The dispatch
	// lives here rather than inside benchmark.Run because everything that
	// class is about (concurrency, rate limiting, latency histogram) has no
	// EOS analogue, and folding two entirely different loops into one
	// function would make both harder to follow.
	if g.Protocol == repository.ProtocolEos {
		return sweepEosGame(ctx, repo, chWriter, eosClientFromEnv(), g, write)
	}

	res := sweepResult{Slug: g.Slug}
	started := time.Now()

	servers, loaded, err := repo.LoadForGame(ctx, g)
	if err != nil {
		return res, fmt.Errorf("load servers: %w", err)
	}

	cfg := baseCfg
	cfg.GameSlug = g.Slug
	cfg.GameName = g.Name
	cfg.GameID = uint32(g.ID)

	if write {
		cfg.Writer = repo.NewWriter(g.ID)
		cfg.Stats = chWriter // shared with the whole run, may be nil
	}

	// Snapshot before so we can report how many rows THIS game added to
	// the shared CH buffer (rather than the cumulative count for every
	// game already processed).
	var chBefore chstats.Stats
	if chWriter != nil {
		chBefore = chWriter.Snapshot()
	}

	slog.Info("sweep start",
		"game", g.Slug,
		"protocol", g.Protocol,
		"valid_endpoints", loaded.Valid,
	)

	report, err := benchmark.Run(ctx, cfg, servers, loaded)
	if err != nil {
		return res, err
	}

	// Games counters only make sense alongside the state writes — no point
	// updating them if the sweep did not persist anything. Fail-open on
	// error: the sweep succeeded, the counters can catch up on the next
	// run (or via CatalogCounters). A short-cache-of-a-minute UI reads
	// them, so a delayed counter is a cosmetic issue, not a data one.
	if write {
		if err := repo.UpdateGameCounters(ctx, g.ID, report.Responded, report.PlayersOnline); err != nil {
			slog.Warn("update game counters failed", "game", g.Slug, "err", err)
		}
		// Invalidate the Laravel API cache so the nav rail on the homepage
		// picks up the fresh numbers within seconds instead of the full
		// 10-minute TTL. Fail-open — the sweep is already committed.
		forgetGamesCache(ctx)
	}

	res.ElapsedMs = time.Since(started).Milliseconds()
	res.SweepMs = report.ElapsedMs
	res.Responded = report.Responded
	res.PlayersOnline = report.PlayersOnline
	res.Timeouts = report.Timeouts
	res.NetworkErrors = report.NetworkErrors
	if report.Writer != nil {
		res.DBWritten = report.Writer.Written
		res.DBErrors = report.Writer.Errors
		res.DBRetries = report.Writer.Retries
	}
	if chWriter != nil {
		res.CHEnqueued = chWriter.Snapshot().Enqueued - chBefore.Enqueued
	}

	// One structured "done" line per game — this is what a cron-driven log
	// file needs to be searchable ("how did last night's sweep go?"). The
	// pretty summary in stdout stays for terminal use.
	//
	// elapsed_ms is the full wall clock — sweep, DB writes, CH flush,
	// game-counter UPDATE, Redis DEL — so a cron operator can tell whether
	// the ten-minute schedule still fits. sweep_ms breaks out just the
	// sweep+writes portion for finer accounting.
	done := []any{
		"game", g.Slug,
		"elapsed_ms", res.ElapsedMs,
		"sweep_ms", res.SweepMs,
		"responded", res.Responded,
		"players_online", res.PlayersOnline,
		"timeouts", res.Timeouts,
		"net_errors", res.NetworkErrors,
	}
	if report.Writer != nil {
		done = append(done,
			"db_written", res.DBWritten,
			"db_errors", res.DBErrors,
			"db_retries", res.DBRetries,
		)
	}
	if chWriter != nil {
		done = append(done, "ch_enqueued", res.CHEnqueued)
	}
	slog.Info("sweep done", done...)

	return res, nil
}

func usage() {
	out := flag.CommandLine.Output()
	fmt.Fprintf(out, `a2s-benchmark — sweep game servers, measure, and (optionally) write results.

Usage:
  a2s-benchmark [flags] --game=<slug>
  a2s-benchmark [flags] --all-games

Flags:
`)
	flag.PrintDefaults()
	fmt.Fprint(out, `
DSN resolution (highest priority wins):
  1. --dsn=<url>
  2. $A2S_BENCHMARK_DSN
  3. DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD from .env
     (same variables Laravel uses; assembles postgres://... with sslmode=disable)

Optional ClickHouse stats (when --write is on and CH_HOST is set):
  CH_HOST, CH_PORT (9000), CH_DATABASE (lobbyhub_stats),
  CH_USERNAME (default), CH_PASSWORD
  → one row per online server per sweep, ts truncated to the 10-minute mark.

Optional Redis cache invalidation (when --write is on):
  REDIS_HOST, REDIS_PORT, REDIS_PASSWORD, REDIS_CACHE_DB (1),
  REDIS_PREFIX, CACHE_PREFIX, APP_NAME
  → DEL of Laravel's api:games cache key after each game sweep.

Examples:

  # Whole catalog, production write mode, optimal values
  ulimit -n 65536
  ./a2s-benchmark.bin --all-games --concurrency=3000 --timeout=500ms --write

  # One game, write mode
  ./a2s-benchmark.bin --game=counter-strike-2 --concurrency=3000 --timeout=500ms --write

  # Minecraft — TCP handshake is heavier, keep concurrency moderate
  ./a2s-benchmark.bin --game=minecraft --concurrency=500 --timeout=1s --write

  # Read-only benchmark (no DB writes) with reproducible sample
  ./a2s-benchmark.bin --game=rust --concurrency=3000 --timeout=500ms --limit=20000 --seed=42

  # One-off full audit — slower cycle, one retry to recover packet loss
  ./a2s-benchmark.bin --all-games --concurrency=3000 --timeout=1s --retries=1 --write

  # Point at a non-default .env
  ./a2s-benchmark.bin --all-games --env=/etc/lobbyhub/.env --write

  # Cron-driven full sweep with rotating log file
  ./a2s-benchmark.bin --all-games --write \
    --log-file=/var/log/lobbyhub-sweep.log --log-level=info

Notes:
  - Requires ulimit -n 65536 (or higher) once per shell for concurrency >= 3000.
  - --write needs UPDATE on server_states; lobbyhub_user has it.
  - --all-games skips games whose protocol isn't implemented (fivem today).
`)
}
