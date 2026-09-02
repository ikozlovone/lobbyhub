package main

import (
	"context"
	"fmt"
	"log/slog"
	"os"
	"strconv"
	"time"

	"github.com/lobbyhub/a2s-benchmark/internal/chstats"
	"github.com/lobbyhub/a2s-benchmark/internal/eos"
	"github.com/lobbyhub/a2s-benchmark/internal/repository"
	"github.com/lobbyhub/a2s-benchmark/internal/snapshot"
)

// sweepEosGame is the EOS analogue of benchmark.Run — one game, load its
// rows, pull the matchmaking session list once, decide online/offline per
// row from the address map, and hand the results to the same Writer +
// ClickHouse Writer everything else uses.
//
// No concurrency, no UDP, no per-server latency histogram: an EOS sweep is a
// paginated HTTP walk (~30 pages for ARK's ~6k sessions) followed by a
// straight map-and-enqueue. The runner's Config still shapes the writes —
// GameID, GameSlug, Writer, Stats — but every field that talks about
// bandwidth (Concurrency, RatePerSec, Retries) has no EOS analogue and is
// ignored by this path.
func sweepEosGame(
	ctx context.Context,
	repo *repository.Repo,
	chWriter *chstats.Writer,
	eosClient *eos.Client,
	g repository.GameInfo,
	write bool,
) (sweepResult, error) {
	res := sweepResult{Slug: g.Slug}
	started := time.Now()

	servers, loaded, err := repo.LoadForGame(ctx, g)
	if err != nil {
		return res, fmt.Errorf("load servers: %w", err)
	}

	dep, err := eos.ResolveFromEnv(g.Slug)
	if err != nil {
		return res, err
	}

	fmt.Printf("Game: %s (%s)\n", g.Name, g.Protocol)
	fmt.Printf("Servers loaded: %d\n", loaded.Total)
	fmt.Printf("Valid endpoints: %d\n", loaded.Valid)
	if loaded.MissingQueryPort > 0 {
		fmt.Printf("Missing query_port: %d\n", loaded.MissingQueryPort)
	}
	if loaded.MissingIP > 0 {
		fmt.Printf("Missing ip_address: %d\n", loaded.MissingIP)
	}
	fmt.Println("Pulling EOS matchmaking list...")

	slog.Info("eos sweep start", "game", g.Slug, "deployment", dep.DeploymentID, "servers_loaded", loaded.Valid)

	sweep, err := eos.Sweep(ctx, eosClient, dep, 0)
	if err != nil {
		return res, fmt.Errorf("eos sweep: %w", err)
	}

	// Address map, `ip:port`, matching how Server.Endpoint is composed for
	// EOS games (pickPort returns the game port for ProtocolEos).
	byAddress := make(map[string]*eos.Session, len(sweep.Sessions))
	for _, s := range sweep.Sessions {
		byAddress[s.AddressKey()] = s
	}

	var writer *repository.Writer
	if write {
		writer = repo.NewWriter(g.ID)
	}

	var (
		responded     int64
		playersOnline int64
		offline       int64
	)
	now := time.Now().UTC()

	for _, srv := range servers {
		if session, ok := byAddress[srv.Endpoint]; ok {
			// Synthesise an "online" snapshot in the shape the writer's
			// VALUES-driven UPDATE understands. Only the fields EOS actually
			// carries are set — bots, VAC, steam_id, game_port stay nil and
			// COALESCE keeps whatever the row already had.
			maxPlayers := session.PlayersMax
			info := &snapshot.Info{
				PlayersOnline: session.PlayersOnline,
				PlayersMax:    &maxPlayers,
				Map:           session.Map,
				Version:       session.Version,
				MOTD:          session.Name,
			}
			snap := snapshot.Snapshot{
				Outcome: snapshot.OutcomeResponded,
				Info:    info,
			}

			if writer != nil {
				writer.Enqueue(srv.ID, snap, now)
			}
			// ClickHouse: only for the sessions that actually reported a
			// count. Same rule the A2S path follows — an offline row would
			// only inflate the history table with zeros.
			if chWriter != nil {
				chWriter.Enqueue(uint32(g.ID), uint64(srv.ID), safeUint16(session.PlayersOnline))
			}

			responded++
			playersOnline += int64(session.PlayersOnline)
		} else {
			// Not in the current list — treat as offline, same way A2S does
			// a timeout. failed_queries_count increments, players_online → 0.
			// The catalog row stays; nothing here decides to delete anything.
			snap := snapshot.Snapshot{Outcome: snapshot.OutcomeTimeout}
			if writer != nil {
				writer.Enqueue(srv.ID, snap, now)
			}
			offline++
		}
	}

	if writer != nil {
		closeCtx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
		if err := writer.Close(closeCtx); err != nil {
			slog.Warn("eos writer close failed", "game", g.Slug, "err", err)
		}
		cancel()
	}

	elapsed := time.Since(started)

	// Print the sweep summary in the same block shape benchmark.Run uses —
	// the operator reading it should not have to switch mental model between
	// A2S games and EOS games.
	fmt.Println()
	fmt.Printf("EOS sweep finished\n\n")
	fmt.Printf("Sessions:\n")
	fmt.Printf("  fetched (raw):      %d\n", sweep.Found)
	fmt.Printf("  distinct:           %d\n", sweep.Distinct)
	fmt.Printf("  pages:              %d\n", sweep.Pages)
	fmt.Println()
	fmt.Printf("Servers:\n")
	fmt.Printf("  loaded:             %d\n", loaded.Total)
	fmt.Printf("  matched (online):   %d\n", responded)
	fmt.Printf("  unmatched (offline):%d\n", offline)
	fmt.Println()
	fmt.Printf("Timing:\n")
	fmt.Printf("  total:              %s\n", elapsed.Round(time.Millisecond))
	fmt.Printf("  eos http:           %d ms\n", sweep.HTTPMs)
	fmt.Println()

	res.ElapsedMs = elapsed.Milliseconds()
	res.SweepMs = elapsed.Milliseconds()
	res.Responded = responded
	res.PlayersOnline = playersOnline

	// One structured "done" event to match what benchmark.Run emits for A2S
	// sweeps — a log-tail on a cron install should be searchable the same
	// way regardless of which protocol the game uses.
	done := []any{
		"game", g.Slug,
		"protocol", string(g.Protocol),
		"elapsed_ms", res.ElapsedMs,
		"sweep_ms", res.SweepMs,
		"sessions_found", sweep.Found,
		"sessions_distinct", sweep.Distinct,
		"pages", sweep.Pages,
		"responded", responded,
		"players_online", playersOnline,
		"offline", offline,
	}
	if writer != nil {
		s := writer.Stats()
		res.DBWritten = s.Written
		res.DBErrors = s.Errors
		res.DBRetries = s.Retries
		done = append(done, "db_written", s.Written, "db_errors", s.Errors, "db_retries", s.Retries)
	}
	slog.Info("sweep done", done...)

	// Counters + Redis: same rules as the A2S path. `UpdateGameCounters`
	// writes online_servers_count + players_online, and forgetGamesCache
	// drops the Laravel API cache so the site's nav rail picks up fresh
	// numbers within seconds.
	if write {
		if err := repo.UpdateGameCounters(ctx, g.ID, responded, playersOnline); err != nil {
			slog.Warn("update game counters failed", "game", g.Slug, "err", err)
		}
		forgetGamesCache(ctx)
	}

	return res, nil
}

// eosClientFromEnv reads the same env vars the PHP side reads
// (`config/services.php`'s `eos` block), so a deployment configures both
// sweepers with one place to look.
func eosClientFromEnv() *eos.Client {
	baseURL := getenvDefault("EOS_BASE_URL", "https://api.epicgames.dev")
	timeout := time.Duration(getenvInt("EOS_TIMEOUT", 30)) * time.Second
	attempts := getenvInt("EOS_ATTEMPTS", 4)
	pauseMs := getenvInt("EOS_PAUSE_MS", 250)
	pageSize := getenvInt("EOS_PAGE_SIZE", 200)

	return eos.New(baseURL, timeout, attempts, pauseMs, pageSize)
}

func getenvDefault(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func getenvInt(key string, fallback int) int {
	v := os.Getenv(key)
	if v == "" {
		return fallback
	}
	n, err := strconv.Atoi(v)
	if err != nil || n <= 0 {
		return fallback
	}
	return n
}

// safeUint16 clamps a player count into the ClickHouse column's range
// (UInt16, 0..65535). Values past the cap would silently wrap; they should
// not exist (ASA max is 70), but the cap protects the graph from a poisoned
// session reporting a huge number.
func safeUint16(n int) uint16 {
	if n < 0 {
		return 0
	}
	if n > 65535 {
		return 65535
	}
	return uint16(n)
}
