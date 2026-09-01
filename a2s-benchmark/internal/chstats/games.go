package chstats

import (
	"context"
	"fmt"
	"time"
)

// The game side of the stats tables.
//
// `server_players_raw` answers "how many people are on this server". These
// answer "how many people are in this game, anywhere on Steam" — a different
// number from a different source (Valve's charts and player-count endpoints,
// not the master server), and one no server list can produce.
//
// Written here rather than in a package of their own because the connection,
// the pool sizing and the failure policy are already decided in client.go and
// there is nothing about a game row that wants different ones.

// GamePoint is one game's reading at one tick.
type GamePoint struct {
	AppID     uint32
	GameID    uint32 // ours, 0 for a game the catalog does not carry
	Players   uint32
	Rank      uint16 // position in Steam's top 100, 0 outside it
	PeakToday uint32 // Steam's own 24h peak, 0 when the tick came from a lookup
}

// EnsureGameTables creates them if they are not there.
//
// Run on every start rather than kept in a migration somewhere: there are two
// of them, they are `IF NOT EXISTS`, and a collector that cannot be pointed at
// a fresh ClickHouse without a separate ritual is a collector that will one
// day be pointed at one anyway. schema/game_players.sql is the readable copy.
func (c *Client) EnsureGameTables(ctx context.Context) error {
	statements := []string{
		`CREATE TABLE IF NOT EXISTS game_players_raw
		(
			ts         DateTime,
			app_id     UInt32,
			game_id    UInt32,
			players    UInt32,
			rank       UInt16,
			peak_today UInt32
		)
		ENGINE = MergeTree
		PARTITION BY toYYYYMM(ts)
		ORDER BY (app_id, ts)
		TTL ts + INTERVAL 180 DAY`,

		`CREATE TABLE IF NOT EXISTS game_players_daily
		(
			date        Date,
			app_id      UInt32,
			game_id     UInt32,
			players_avg Float32,
			players_max UInt32,
			players_min UInt32,
			samples     UInt32,
			best_rank   UInt16
		)
		ENGINE = ReplacingMergeTree
		ORDER BY (app_id, date)`,
	}

	for _, statement := range statements {
		if err := c.conn.Exec(ctx, statement); err != nil {
			return fmt.Errorf("ensure game tables: %w", err)
		}
	}
	return nil
}

// InsertGamePoints writes one tick as a single batch.
//
// Every row carries the same `ts` — the tick's own ten-minute mark — so a
// reader can group by it without caring how long the collection took, which
// is the same rule the server sweep's writer follows.
func (c *Client) InsertGamePoints(ctx context.Context, ts time.Time, points []GamePoint) (int, error) {
	if len(points) == 0 {
		return 0, nil
	}

	batch, err := c.conn.PrepareBatch(ctx,
		"INSERT INTO game_players_raw (ts, app_id, game_id, players, rank, peak_today)")
	if err != nil {
		return 0, fmt.Errorf("prepare game batch: %w", err)
	}

	for _, p := range points {
		if err := batch.Append(ts, p.AppID, p.GameID, p.Players, p.Rank, p.PeakToday); err != nil {
			return 0, fmt.Errorf("append game row: %w", err)
		}
	}

	if err := batch.Send(); err != nil {
		return 0, fmt.Errorf("send game batch: %w", err)
	}
	return len(points), nil
}

// RollupGameDay folds one day of ticks into `game_players_daily`.
//
// INSERT ... SELECT, so the rows never leave ClickHouse. Safe to re-run: the
// daily table is a ReplacingMergeTree keyed on (app_id, date), so a second
// pass over a day supersedes the first rather than doubling it. Reads that
// cannot tolerate seeing both before a merge use FINAL.
//
// `best_rank` is the lowest rank the game held that day, with the zeroes that
// mean "not charted" mapped out of the way first and back to 0 if that is all
// there was — a game that spent the day outside the top 100 has no best rank,
// not rank 65535.
func (c *Client) RollupGameDay(ctx context.Context, day time.Time) error {
	date := day.UTC().Format("2006-01-02")

	// The date is formatted from a time.Time rather than taken from input, so
	// there is nothing here for a quote to escape.
	query := fmt.Sprintf(`
		INSERT INTO game_players_daily
			(date, app_id, game_id, players_avg, players_max, players_min, samples, best_rank)
		SELECT
			toDate(ts)                              AS date,
			app_id,
			max(game_id)                            AS game_id,
			avg(players)                            AS players_avg,
			max(players)                            AS players_max,
			min(players)                            AS players_min,
			count()                                 AS samples,
			if(min(if(rank = 0, 65535, rank)) = 65535, 0, min(if(rank = 0, 65535, rank))) AS best_rank
		FROM game_players_raw
		WHERE toDate(ts) = toDate('%s')
		GROUP BY date, app_id`, date)

	if err := c.conn.Exec(ctx, query); err != nil {
		return fmt.Errorf("roll up %s: %w", date, err)
	}
	return nil
}
