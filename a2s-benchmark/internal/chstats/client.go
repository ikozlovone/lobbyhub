// Package chstats writes per-server player-count samples to ClickHouse.
//
// Read the outline in the tool's README ("Stats writes"): one raw table
// (`server_players_raw`) holds seven days of ten-minute samples per server,
// a daily rollup (`server_players_daily`) is filled by a cron INSERT
// against the raw. This package only writes the raw side; the rollup lives
// outside the tool.
//
// Everything is fail-open — the sweep and the state writer must not depend
// on ClickHouse being reachable. If CH_HOST is empty we simply skip stats.
package chstats

import (
	"context"
	"fmt"
	"os"
	"strconv"
	"time"

	"github.com/ClickHouse/clickhouse-go/v2"
	"github.com/ClickHouse/clickhouse-go/v2/lib/driver"
)

// Options is what NewClient needs. Read from env in main.
type Options struct {
	Host     string
	Port     int
	Database string
	Username string
	Password string
}

// FromEnv reads the CH_* variables. Returns ok=false when CH_HOST is not
// set — that is the signal that this environment isn't using ClickHouse
// and the whole stats path should be a no-op.
func FromEnv() (Options, bool) {
	host := os.Getenv("CH_HOST")
	if host == "" {
		return Options{}, false
	}
	port := 9000
	if s := os.Getenv("CH_PORT"); s != "" {
		if n, err := strconv.Atoi(s); err == nil {
			port = n
		}
	}
	db := os.Getenv("CH_DATABASE")
	if db == "" {
		db = "lobbyhub_stats"
	}
	user := os.Getenv("CH_USERNAME")
	if user == "" {
		user = "default"
	}
	return Options{
		Host:     host,
		Port:     port,
		Database: db,
		Username: user,
		Password: os.Getenv("CH_PASSWORD"),
	}, true
}

// Client wraps one native-protocol connection reused across the whole
// process. Cheap to open, expensive to open per game — so we do it once
// and hand out per-game writers on top.
type Client struct {
	conn driver.Conn
	opts Options
}

// Open dials ClickHouse and verifies the connection with a ping. A
// connection that could not ping fails loudly rather than being cached
// broken — a wedged CH is a rare problem worth noticing at startup,
// not one that shows up as silent zeros in the summary later.
func Open(ctx context.Context, opts Options) (*Client, error) {
	conn, err := clickhouse.Open(&clickhouse.Options{
		Addr: []string{fmt.Sprintf("%s:%d", opts.Host, opts.Port)},
		Auth: clickhouse.Auth{
			Database: opts.Database,
			Username: opts.Username,
			Password: opts.Password,
		},
		// The tool writes small batches from one process — we do not need
		// dozens of TCP connections. A pool of two is enough for overlap
		// between one INSERT completing and the next one starting.
		MaxOpenConns:    2,
		MaxIdleConns:    2,
		ConnMaxLifetime: time.Hour,
		DialTimeout:     5 * time.Second,
	})
	if err != nil {
		return nil, fmt.Errorf("open ClickHouse: %w", err)
	}
	pingCtx, cancel := context.WithTimeout(ctx, 3*time.Second)
	defer cancel()
	if err := conn.Ping(pingCtx); err != nil {
		conn.Close()
		return nil, fmt.Errorf("ping ClickHouse: %w", err)
	}
	return &Client{conn: conn, opts: opts}, nil
}

func (c *Client) Close() error {
	if c == nil {
		return nil
	}
	return c.conn.Close()
}

// NewSweepWriter returns a single collector shared across every game in
// a sweep. The timestamp on every row is captured now, truncated down
// to the nearest ten-minute boundary — so all rows in one sweep share
// the same ts and drop into the same time bucket in ClickHouse
// regardless of how long the sweep itself took.
//
// game_id lives on each queued point (see writer.go) rather than on the
// Writer itself, so main only creates one of these per --all-games run.
// One PrepareBatch/Send per sweep replaces the 46 the earlier per-game
// design used to do — the same rows land in ClickHouse as one large
// part instead of forty-six small ones.
func (c *Client) NewSweepWriter() *Writer {
	return &Writer{
		client: c,
		ts:     time.Now().UTC().Truncate(10 * time.Minute),
		points: make([]point, 0, 262144),
	}
}

// RawPoint is one row for server_players_raw. Ts carries the original
// timestamp — unlike Writer, which pins one ts for a whole sweep, backfill
// needs to preserve the timestamp each historical sample had.
type RawPoint struct {
	Ts            time.Time
	GameID        uint32
	ServerID      uint64
	PlayersOnline uint16
}

// InsertRawBatch pushes every row as one batched INSERT. Returns rows
// actually written on success; on error, the CH-side error verbatim.
//
// Idempotency is the caller's problem — the raw table is MergeTree, so a
// second call with the same rows produces duplicates. Backfill mode
// truncates first; the sweeper writes at most one row per sweep-ts per
// server, so re-runs there are also on the caller.
func (c *Client) InsertRawBatch(ctx context.Context, batch []RawPoint) (int, error) {
	if len(batch) == 0 {
		return 0, nil
	}
	prep, err := c.conn.PrepareBatch(ctx,
		"INSERT INTO server_players_raw (ts, game_id, server_id, players_online)")
	if err != nil {
		return 0, fmt.Errorf("prepare raw batch: %w", err)
	}
	for _, p := range batch {
		if err := prep.Append(p.Ts, p.GameID, p.ServerID, p.PlayersOnline); err != nil {
			return 0, fmt.Errorf("append raw row: %w", err)
		}
	}
	if err := prep.Send(); err != nil {
		return 0, fmt.Errorf("send raw batch: %w", err)
	}
	return len(batch), nil
}

// DailyPoint is one row for server_players_daily. Date is the local calendar
// day; ClickHouse stores as Date (three bytes), Go's time.Time works fine.
type DailyPoint struct {
	Date         time.Time
	GameID       uint32
	ServerID     uint64
	PlayersAvg   float32
	PlayersMax   uint16
	PlayersMin   uint16
	SamplesCount uint32
}

// InsertDailyBatch pushes daily rollup rows.
func (c *Client) InsertDailyBatch(ctx context.Context, batch []DailyPoint) (int, error) {
	if len(batch) == 0 {
		return 0, nil
	}
	prep, err := c.conn.PrepareBatch(ctx,
		"INSERT INTO server_players_daily (date, game_id, server_id, players_avg, players_max, players_min, samples_count)")
	if err != nil {
		return 0, fmt.Errorf("prepare daily batch: %w", err)
	}
	for _, p := range batch {
		if err := prep.Append(p.Date, p.GameID, p.ServerID, p.PlayersAvg, p.PlayersMax, p.PlayersMin, p.SamplesCount); err != nil {
			return 0, fmt.Errorf("append daily row: %w", err)
		}
	}
	if err := prep.Send(); err != nil {
		return 0, fmt.Errorf("send daily batch: %w", err)
	}
	return len(batch), nil
}

// Exec runs an arbitrary SQL statement without a result set — useful for
// TRUNCATE, ALTER, OPTIMIZE and similar maintenance work the backfill
// binary needs to do before or after a bulk load.
func (c *Client) Exec(ctx context.Context, query string) error {
	return c.conn.Exec(ctx, query)
}
