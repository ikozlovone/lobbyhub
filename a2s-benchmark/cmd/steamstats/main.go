// steamstats records how many people are playing each game, as Steam counts
// them.
//
// The sibling of a2s-benchmark and deliberately not part of it. That one asks
// every server in the catalog how many players are on it; this one asks Valve
// how many players a game has anywhere on Steam — a number no server list can
// produce, from endpoints that have nothing to do with the master server. The
// two answer different questions and belong on different schedules: a sweep of
// three hundred thousand servers is minutes of UDP, and this is one HTTPS
// request plus one per game below the top hundred.
//
// A run is one tick:
//
//   1. read the games that have a `steam_appid` from Postgres,
//   2. fetch Valve's official top 100 in a single request,
//   3. look up, one request each, the catalog games the chart did not cover,
//   4. write it all to ClickHouse as one batch, stamped with the tick's own
//      ten-minute mark — the same bucket rule the server sweep uses,
//   5. and write the current numbers back to `games`, so a page render reads
//      them without touching ClickHouse.
//
// Games in the chart that this catalog does not carry are recorded too, with
// game_id 0. They cost nothing (the chart came in one request either way) and
// they are the answer to "which games with a live playerbase are we missing".
//
// Meant for cron, every ten minutes:
//
//   */10 * * * * /usr/local/bin/steamstats --env=/var/www/lobbyhub/.env \
//                    --log-file=/var/log/lobbyhub/steamstats.log
//
// and once after midnight UTC for the day that just ended:
//
//   20 0 * * * /usr/local/bin/steamstats --env=/var/www/lobbyhub/.env --rollup
package main

import (
	"context"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"sort"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/lobbyhub/a2s-benchmark/internal/applog"
	"github.com/lobbyhub/a2s-benchmark/internal/chstats"
	"github.com/lobbyhub/a2s-benchmark/internal/envfile"
	"github.com/lobbyhub/a2s-benchmark/internal/repository"
	"github.com/lobbyhub/a2s-benchmark/internal/steam"
)

func main() {
	os.Exit(run())
}

func run() int {
	var (
		envPath     = flag.String("env", ".env", "Path to a Laravel-style .env; missing file is silently skipped")
		dsn         = flag.String("dsn", "", "Postgres DSN (overrides A2S_BENCHMARK_DSN and DB_* env vars)")
		allGames    = flag.Bool("all-games", false, "Include games that are switched off, not only the ones the site shows")
		concurrency = flag.Int("concurrency", 4, "Parallel per-game lookups for the games the chart did not cover")
		timeoutStr  = flag.String("timeout", "15s", "Deadline for one request to Valve")
		dryRun      = flag.Bool("dry-run", false, "Collect and report, writing nothing to ClickHouse")
		rollup      = flag.Bool("rollup", false, "Fold a day of ticks into game_players_daily instead of collecting")
		rollupDate  = flag.String("rollup-date", "", "Day to roll up as YYYY-MM-DD (default: yesterday, UTC)")
		logLevel    = flag.String("log-level", "info", "slog level: debug|info|warn|error")
		logFile     = flag.String("log-file", "", "Also write structured logs to this rotating file (empty = stderr only)")
	)
	flag.Usage = usage
	flag.Parse()

	logCloser := applog.Setup(applog.Config{Level: *logLevel, File: *logFile})
	defer logCloser.Close()

	if loaded, err := envfile.Load(*envPath); err != nil {
		fmt.Fprintf(os.Stderr, "env: %v\n", err)
		return 2
	} else if loaded {
		fmt.Fprintf(os.Stderr, "env: loaded %s\n", *envPath)
	}

	timeout, err := time.ParseDuration(*timeoutStr)
	if err != nil {
		fmt.Fprintf(os.Stderr, "bad --timeout: %v\n", err)
		return 2
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	// ClickHouse is where everything this collects goes, so unlike the sweep —
	// which writes state to Postgres whether or not stats are configured —
	// there is no useful degraded mode here. Missing CH_HOST is a failure.
	opts, ok := chstats.FromEnv()
	if !ok {
		fmt.Fprintln(os.Stderr, "CH_HOST is not set: steamstats has nowhere to write")
		return 2
	}

	ch, err := chstats.Open(ctx, opts)
	if err != nil {
		fmt.Fprintf(os.Stderr, "open ClickHouse: %v\n", err)
		return 1
	}
	defer ch.Close()

	if err := ch.EnsureGameTables(ctx); err != nil {
		fmt.Fprintf(os.Stderr, "%v\n", err)
		return 1
	}

	if *rollup {
		return runRollup(ctx, ch, *rollupDate)
	}

	resolvedDSN := resolveDSN(*dsn)
	if resolvedDSN == "" {
		fmt.Fprintln(os.Stderr, "postgres DSN not set: pass --dsn, export A2S_BENCHMARK_DSN, or set DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD in .env")
		return 2
	}

	repo, err := repository.Open(ctx, resolvedDSN)
	if err != nil {
		fmt.Fprintf(os.Stderr, "open db: %v\n", err)
		return 1
	}
	defer repo.Close()

	return collect(ctx, repo, ch, steam.New(timeout), collectConfig{
		AllGames:    *allGames,
		Concurrency: *concurrency,
		DryRun:      *dryRun,
	})
}

type collectConfig struct {
	AllGames    bool
	Concurrency int
	DryRun      bool
}

func collect(ctx context.Context, repo *repository.Repo, ch *chstats.Client, client *steam.Client, cfg collectConfig) int {
	started := time.Now()

	// The tick's mark, taken before any of the work: every row in this batch
	// shares it, so a reader groups by it without caring that the lookups took
	// forty seconds. Same rule as the server sweep's writer.
	tick := time.Now().UTC().Truncate(10 * time.Minute)

	games, err := repo.ListSteamGames(ctx, !cfg.AllGames)
	if err != nil {
		fmt.Fprintf(os.Stderr, "%v\n", err)
		return 1
	}

	ours := make(map[uint32]repository.SteamGame, len(games))
	for _, g := range games {
		ours[g.AppID] = g
	}

	entries, err := client.Chart(ctx)
	if err != nil {
		// Without the chart every catalog game becomes a lookup of its own,
		// which is a worse run rather than no run: the numbers are the same,
		// the rank and the peak are what is lost.
		slog.Warn("steam chart unavailable, falling back to per-game lookups", "err", err)
		entries = nil
	}

	points := make([]chstats.GamePoint, 0, len(entries)+len(games))
	charted := make(map[uint32]bool, len(entries))

	// The same readings, for the copy `games` keeps. Only for games we carry:
	// there is no row to update for the rest.
	catalog := make([]repository.SteamPlayers, 0, len(games))

	for _, e := range entries {
		charted[e.AppID] = true

		mine, ok := ours[e.AppID]

		points = append(points, chstats.GamePoint{
			AppID:     e.AppID,
			GameID:    uint32(mine.ID), // 0 when the catalog has no such game
			Players:   e.Players,
			Rank:      e.Rank,
			PeakToday: e.PeakToday,
		})

		if ok {
			rank := e.Rank
			catalog = append(catalog, repository.SteamPlayers{
				GameID: mine.ID,
				Online: e.Players,
				Peak:   e.PeakToday,
				Rank:   &rank,
			})
		}
	}

	// What the chart did not cover: our games outside the top 100, one request
	// each. Sorted so a run's log reads the same way twice.
	var missing []repository.SteamGame
	for _, g := range games {
		if !charted[g.AppID] {
			missing = append(missing, g)
		}
	}
	sort.Slice(missing, func(i, j int) bool { return missing[i].ID < missing[j].ID })

	looked, failed := lookup(ctx, client, missing, cfg.Concurrency, &points, &catalog)

	slog.Info("steam tick",
		"ts", tick.Format(time.RFC3339),
		"catalog_games", len(games),
		"charted", len(entries),
		"looked_up", looked,
		"lookup_errors", failed,
		"rows", len(points),
	)

	if cfg.DryRun {
		fmt.Printf("dry run: %d rows for %s, %d game(s) would be updated (%d charted, %d looked up, %d lookup errors)\n",
			len(points), tick.Format("2006-01-02 15:04"), len(catalog), len(entries), looked, failed)
		return 0
	}

	written, err := ch.InsertGamePoints(ctx, tick, points)
	if err != nil {
		fmt.Fprintf(os.Stderr, "write: %v\n", err)
		return 1
	}

	// And the current value back into the catalog, where a page render can
	// read it without touching ClickHouse. Fail-open: the history is written
	// and safe, and a games table one tick out of date is a cosmetic problem
	// that the next run fixes by itself.
	updated, err := repo.UpdateSteamPlayers(ctx, catalog)
	if err != nil {
		slog.Warn("games not updated", "err", err)
	}

	fmt.Printf("%s  %d rows written, %d game(s) updated (%d charted, %d looked up, %d lookup errors) in %s\n",
		tick.Format("2006-01-02 15:04"),
		written,
		updated,
		len(entries),
		looked,
		failed,
		time.Since(started).Round(time.Millisecond),
	)

	// A tick that could not reach Valve for some of the catalog still wrote the
	// rest; the exit code is what a cron mail is triggered by.
	if failed > 0 {
		return 1
	}
	return 0
}

// lookup fills in the games the chart did not carry.
//
// A small pool rather than one request at a time, and a small one rather than
// a big one: this is somebody else's public API being asked a few dozen
// questions every ten minutes, and there is no deadline here worth crowding it
// for. Results go through a mutex because the slice is the only shared thing
// and it is touched once per game.
func lookup(
	ctx context.Context,
	client *steam.Client,
	games []repository.SteamGame,
	concurrency int,
	points *[]chstats.GamePoint,
	catalog *[]repository.SteamPlayers,
) (looked int, failed int) {
	if len(games) == 0 {
		return 0, 0
	}
	if concurrency < 1 {
		concurrency = 1
	}

	var (
		mu   sync.Mutex
		wg   sync.WaitGroup
		work = make(chan repository.SteamGame)
	)

	for i := 0; i < concurrency; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for g := range work {
				players, ok, err := client.PlayerCount(ctx, g.AppID)

				mu.Lock()
				switch {
				case err != nil:
					failed++
					slog.Warn("player count failed", "game", g.Slug, "app_id", g.AppID, "err", err)
				case !ok:
					// Valve says the app has no count to give — a tool, or
					// something unreleased. Not an error, and not a zero:
					// writing one would draw a line at the bottom of a chart
					// for a game that never reported anything.
					slog.Debug("no player count published", "game", g.Slug, "app_id", g.AppID)
				default:
					looked++
					*points = append(*points, chstats.GamePoint{
						AppID:   g.AppID,
						GameID:  uint32(g.ID),
						Players: players,
					})
					// No rank and no peak: a per-appid lookup carries neither,
					// and a nil rank is how the column says "not in the top
					// 100" rather than claiming a position.
					*catalog = append(*catalog, repository.SteamPlayers{
						GameID: g.ID,
						Online: players,
					})
				}
				mu.Unlock()
			}
		}()
	}

	for _, g := range games {
		select {
		case work <- g:
		case <-ctx.Done():
			close(work)
			wg.Wait()
			return looked, failed
		}
	}

	close(work)
	wg.Wait()

	return looked, failed
}

func runRollup(ctx context.Context, ch *chstats.Client, date string) int {
	day := time.Now().UTC().AddDate(0, 0, -1)

	if strings.TrimSpace(date) != "" {
		parsed, err := time.Parse("2006-01-02", strings.TrimSpace(date))
		if err != nil {
			fmt.Fprintf(os.Stderr, "bad --rollup-date: %v\n", err)
			return 2
		}
		day = parsed
	}

	if err := ch.RollupGameDay(ctx, day); err != nil {
		fmt.Fprintf(os.Stderr, "%v\n", err)
		return 1
	}

	fmt.Printf("rolled up %s into game_players_daily\n", day.Format("2006-01-02"))
	slog.Info("game rollup done", "date", day.Format("2006-01-02"))
	return 0
}

// resolveDSN picks the DSN the way a2s-benchmark does, and for the same
// reason: the two binaries read one Postgres named one way in one .env.
func resolveDSN(flagValue string) string {
	if strings.TrimSpace(flagValue) != "" {
		return flagValue
	}
	if v := strings.TrimSpace(os.Getenv("A2S_BENCHMARK_DSN")); v != "" {
		return v
	}
	return envfile.BuildDSN()
}

func usage() {
	fmt.Fprintf(os.Stderr, `steamstats — record how many people are playing each game, as Steam counts them.

One tick per run: Valve's official top 100 in a single request, then one
request each for the catalog's games below it, written to ClickHouse as one
batch under a shared ten-minute timestamp.

This is not the master server. It counts people in a game — single-player and
matchmaking included — not players on dedicated servers, which is what
a2s-benchmark and the server tables are for.

Usage:
  steamstats [flags]

Flags:
`)
	flag.PrintDefaults()
	fmt.Fprintf(os.Stderr, `
Environment (from --env or the shell):
  CH_HOST, CH_PORT, CH_DATABASE, CH_USERNAME, CH_PASSWORD   required
  DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD   the catalog

Examples:
  steamstats --env=/var/www/lobbyhub/.env
  steamstats --env=/var/www/lobbyhub/.env --dry-run
  steamstats --env=/var/www/lobbyhub/.env --rollup --rollup-date=2026-08-31
`)
}
