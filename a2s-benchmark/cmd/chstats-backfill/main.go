// chstats-backfill copies historical stats out of Postgres and into
// ClickHouse — a one-shot migration so the graphs on the frontend still
// have data on the day we cut the read path over. Two loads:
//
//   1. server_daily_stats (all history)          → server_players_daily
//   2. server_stats (last N days, is_online=true) → server_players_raw
//
// Idempotency: use --truncate to wipe the CH tables before loading, so a
// second run doesn't stack duplicates. Without --truncate the load runs
// on top of whatever is there — fine for the very first import when the
// CH tables are empty anyway.
package main

import (
	"context"
	"flag"
	"fmt"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/lobbyhub/a2s-benchmark/internal/chstats"
	"github.com/lobbyhub/a2s-benchmark/internal/envfile"
)

func main() {
	os.Exit(run())
}

func run() int {
	var (
		envPath   = flag.String("env", ".env", "Path to a Laravel-style .env")
		dsnFlag   = flag.String("dsn", "", "Postgres DSN (overrides --env)")
		rawDays   = flag.Int("raw-days", 7, "How many days of server_stats to copy into server_players_raw")
		batchSize = flag.Int("batch", 10000, "Rows per ClickHouse INSERT batch")
		truncate  = flag.Bool("truncate", false, "TRUNCATE both ClickHouse tables before loading (destroys existing CH data)")
		onlyRaw   = flag.Bool("only-raw", false, "Copy only server_stats → server_players_raw")
		onlyDaily = flag.Bool("only-daily", false, "Copy only server_daily_stats → server_players_daily")
	)
	flag.Usage = usage
	flag.Parse()

	if _, err := envfile.Load(*envPath); err != nil {
		fmt.Fprintf(os.Stderr, "env: %v\n", err)
		return 2
	}

	dsn := strings.TrimSpace(*dsnFlag)
	if dsn == "" {
		dsn = strings.TrimSpace(os.Getenv("A2S_BENCHMARK_DSN"))
	}
	if dsn == "" {
		dsn = envfile.BuildDSN()
	}
	if dsn == "" {
		fmt.Fprintln(os.Stderr, "postgres DSN not set: pass --dsn or set DB_HOST/DB_DATABASE/etc in .env")
		return 2
	}

	chOpts, ok := chstats.FromEnv()
	if !ok {
		fmt.Fprintln(os.Stderr, "CH_HOST not set — nothing to backfill into")
		return 2
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	pg, err := pgxpool.New(ctx, dsn)
	if err != nil {
		fmt.Fprintf(os.Stderr, "open postgres: %v\n", err)
		return 1
	}
	defer pg.Close()

	ch, err := chstats.Open(ctx, chOpts)
	if err != nil {
		fmt.Fprintf(os.Stderr, "open ClickHouse: %v\n", err)
		return 1
	}
	defer ch.Close()

	if *truncate {
		fmt.Println("Truncating ClickHouse tables...")
		if !*onlyRaw {
			if err := ch.Exec(ctx, "TRUNCATE TABLE server_players_daily"); err != nil {
				fmt.Fprintf(os.Stderr, "truncate daily: %v\n", err)
				return 1
			}
		}
		if !*onlyDaily {
			if err := ch.Exec(ctx, "TRUNCATE TABLE server_players_raw"); err != nil {
				fmt.Fprintf(os.Stderr, "truncate raw: %v\n", err)
				return 1
			}
		}
	}

	if !*onlyRaw {
		if err := loadDaily(ctx, pg, ch, *batchSize); err != nil {
			fmt.Fprintf(os.Stderr, "daily: %v\n", err)
			return 1
		}
	}
	if !*onlyDaily {
		if err := loadRaw(ctx, pg, ch, *rawDays, *batchSize); err != nil {
			fmt.Fprintf(os.Stderr, "raw: %v\n", err)
			return 1
		}
	}

	return 0
}

// loadDaily copies every server_daily_stats row into server_players_daily.
//
// The JOIN pulls game_id from servers so ClickHouse-side queries can filter
// by game without a cross-store lookup. `deleted_at IS NULL` drops rows for
// servers that were soft-deleted since the sample was taken — history for a
// deleted server has no reader.
func loadDaily(ctx context.Context, pg *pgxpool.Pool, ch *chstats.Client, batchSize int) error {
	fmt.Println("Copying server_daily_stats → server_players_daily...")
	started := time.Now()

	rows, err := pg.Query(ctx, `
		SELECT
		    sds.date,
		    s.game_id,
		    sds.server_id,
		    sds.players_avg,
		    sds.players_peak,
		    sds.players_min,
		    sds.samples_count
		FROM server_daily_stats sds
		INNER JOIN servers s ON s.id = sds.server_id
		WHERE s.deleted_at IS NULL
	`)
	if err != nil {
		return fmt.Errorf("query daily: %w", err)
	}
	defer rows.Close()

	batch := make([]chstats.DailyPoint, 0, batchSize)
	total := 0

	for rows.Next() {
		var (
			date         time.Time
			gameID       int64
			serverID     int64
			playersAvg   float64
			playersPeak  int
			playersMin   int
			samplesCount int
		)
		if err := rows.Scan(&date, &gameID, &serverID, &playersAvg, &playersPeak, &playersMin, &samplesCount); err != nil {
			return fmt.Errorf("scan daily: %w", err)
		}
		batch = append(batch, chstats.DailyPoint{
			Date:         date,
			GameID:       uint32(gameID),
			ServerID:     uint64(serverID),
			PlayersAvg:   float32(playersAvg),
			PlayersMax:   uint16(playersPeak),
			PlayersMin:   uint16(playersMin),
			SamplesCount: uint32(samplesCount),
		})
		if len(batch) >= batchSize {
			written, err := ch.InsertDailyBatch(ctx, batch)
			if err != nil {
				return err
			}
			total += written
			if total%(batchSize*10) == 0 {
				fmt.Printf("  ... %d rows\n", total)
			}
			batch = batch[:0]
		}
	}
	if err := rows.Err(); err != nil {
		return fmt.Errorf("iterate daily: %w", err)
	}
	if len(batch) > 0 {
		written, err := ch.InsertDailyBatch(ctx, batch)
		if err != nil {
			return err
		}
		total += written
	}

	fmt.Printf("Daily done: %d rows in %s\n", total, time.Since(started).Round(time.Millisecond))
	return nil
}

// loadRaw copies the last N days of server_stats into server_players_raw,
// keeping only is_online=true samples — the raw table in CH is meant to
// mirror the sweeper's own writes, which are online-only.
//
// Streams row-by-row rather than materialising the whole result set, so
// tens of millions of rows work without RAM blowing out.
func loadRaw(ctx context.Context, pg *pgxpool.Pool, ch *chstats.Client, rawDays int, batchSize int) error {
	fmt.Printf("Copying server_stats (last %d days, is_online=true) → server_players_raw...\n", rawDays)
	started := time.Now()

	rows, err := pg.Query(ctx, `
		SELECT
		    ss.recorded_at,
		    s.game_id,
		    ss.server_id,
		    ss.players_online
		FROM server_stats ss
		INNER JOIN servers s ON s.id = ss.server_id
		WHERE ss.is_online = true
		  AND ss.recorded_at >= NOW() - make_interval(days => $1)
		  AND s.deleted_at IS NULL
	`, rawDays)
	if err != nil {
		return fmt.Errorf("query raw: %w", err)
	}
	defer rows.Close()

	batch := make([]chstats.RawPoint, 0, batchSize)
	total := 0

	for rows.Next() {
		var (
			ts            time.Time
			gameID        int64
			serverID      int64
			playersOnline int
		)
		if err := rows.Scan(&ts, &gameID, &serverID, &playersOnline); err != nil {
			return fmt.Errorf("scan raw: %w", err)
		}
		batch = append(batch, chstats.RawPoint{
			Ts:            ts.UTC(),
			GameID:        uint32(gameID),
			ServerID:      uint64(serverID),
			PlayersOnline: uint16(playersOnline),
		})
		if len(batch) >= batchSize {
			written, err := ch.InsertRawBatch(ctx, batch)
			if err != nil {
				return err
			}
			total += written
			if total%(batchSize*10) == 0 {
				fmt.Printf("  ... %d rows\n", total)
			}
			batch = batch[:0]
		}
	}
	if err := rows.Err(); err != nil {
		return fmt.Errorf("iterate raw: %w", err)
	}
	if len(batch) > 0 {
		written, err := ch.InsertRawBatch(ctx, batch)
		if err != nil {
			return err
		}
		total += written
	}

	fmt.Printf("Raw done: %d rows in %s\n", total, time.Since(started).Round(time.Millisecond))
	return nil
}

// Silences the unused-import complaint until this file grows a real
// use of the pgx package (currently everything goes through pgxpool).
var _ = pgx.ErrNoRows

func usage() {
	out := flag.CommandLine.Output()
	fmt.Fprintf(out, `chstats-backfill — copy Postgres stats into ClickHouse (one-shot).

Usage:
  chstats-backfill [--truncate] [--raw-days=N] [--batch=N]

Flags:
`)
	flag.PrintDefaults()
	fmt.Fprint(out, `
Reads Postgres DSN from --dsn / $A2S_BENCHMARK_DSN / DB_* env; and
ClickHouse from CH_* env. Same rules as a2s-benchmark.

Loads two tables:
  server_daily_stats  → server_players_daily  (all history)
  server_stats        → server_players_raw    (last N days, is_online=true)

Idempotency: --truncate wipes the two ClickHouse tables before loading.
Without it, rows are appended — safe only for the initial import when the
CH side is empty. TRUNCATE is destructive, ask before you run it.

Examples:

  # Fresh import (CH is empty)
  ./chstats-backfill.bin

  # Re-run cleanly
  ./chstats-backfill.bin --truncate

  # Only refresh raw (last 7 days)
  ./chstats-backfill.bin --only-raw --truncate --raw-days=7
`)
}
