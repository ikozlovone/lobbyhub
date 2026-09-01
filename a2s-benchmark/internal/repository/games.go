package repository

import (
	"context"
	"fmt"
	"strings"
)

// SteamGame is a catalog row that Steam has a number for: our id, so a
// ClickHouse row can be read back without a join, and the appid, which is the
// only id Valve answers to.
type SteamGame struct {
	ID     int64
	Slug   string
	AppID  uint32
	Active bool
}

// ListSteamGames returns every game with a `steam_appid`.
//
// `onlyActive` is the ordinary mode: those are the games with a page on the
// site, and a player count nobody can see is a request spent on nothing. The
// wider mode exists for the run after a catalog import, when a few hundred
// games are sitting switched off waiting for somebody to look at them — a week
// of player history is exactly what that decision wants.
//
// Ordered by id so a run is reproducible and a partial one is resumable by eye.
func (r *Repo) ListSteamGames(ctx context.Context, onlyActive bool) ([]SteamGame, error) {
	query := `SELECT id, slug, steam_appid, is_active
	          FROM games
	          WHERE steam_appid IS NOT NULL`
	if onlyActive {
		query += ` AND is_active = true`
	}
	query += ` ORDER BY id`

	rows, err := r.pool.Query(ctx, query)
	if err != nil {
		return nil, fmt.Errorf("list steam games: %w", err)
	}
	defer rows.Close()

	var out []SteamGame
	for rows.Next() {
		var g SteamGame
		var appID int64
		if err := rows.Scan(&g.ID, &g.Slug, &appID, &g.Active); err != nil {
			return nil, fmt.Errorf("scan steam game: %w", err)
		}
		// `steam_appid` is a signed integer column; an appid is not, and one
		// that arrived negative is a row nothing can be asked about.
		if appID <= 0 {
			continue
		}
		g.AppID = uint32(appID)
		out = append(out, g)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("iterate steam games: %w", err)
	}
	return out, nil
}

// SteamPlayers is one game's reading, on its way back to `games`.
//
// Rank is a pointer because the column is nullable and means it: a game
// outside Steam's top 100 has no rank, and writing 0 would sort it above
// Counter-Strike in anything that orders by the column.
type SteamPlayers struct {
	GameID int64
	Online uint32
	Peak   uint32
	Rank   *uint16
}

// UpdateSteamPlayers writes a tick's readings back to the catalog.
//
// Denormalised there because a game page and the rail read these on every
// request and ClickHouse should not be in the path of a page render — the
// history lives in `game_players_raw`, and this is the current value.
//
// One statement for the whole tick rather than one per game: at forty-six
// games either would do, but the same code has to hold when the catalog is
// three hundred, and an UPDATE ... FROM (VALUES ...) is one round trip
// whatever the count. Casts go on the first row because Postgres cannot infer
// the type of a bare placeholder in a VALUES list — the same trick the state
// writer uses.
//
// `steam_stats_synced_at` is the database's own clock rather than this
// process's, for the reason UpdateGameCounters gives: it is the column
// consumers judge freshness by, and they judge it against NOW().
func (r *Repo) UpdateSteamPlayers(ctx context.Context, rows []SteamPlayers) (int64, error) {
	if len(rows) == 0 {
		return 0, nil
	}

	args := make([]any, 0, len(rows)*4)
	tuples := make([]string, 0, len(rows))

	for i, row := range rows {
		n := i * 4
		if i == 0 {
			tuples = append(tuples, fmt.Sprintf(
				"($%d::bigint, $%d::int, $%d::int, $%d::smallint)", n+1, n+2, n+3, n+4))
		} else {
			tuples = append(tuples, fmt.Sprintf("($%d, $%d, $%d, $%d)", n+1, n+2, n+3, n+4))
		}

		var rank any
		if row.Rank != nil {
			rank = int16(*row.Rank)
		}

		args = append(args, row.GameID, int32(row.Online), int32(row.Peak), rank)
	}

	tag, err := r.pool.Exec(ctx, `
		UPDATE games AS g SET
			steam_players_online  = v.online,
			-- A game below the top 100 gets no peak from Steam. Keeping the
			-- last one it published beats replacing a real number with a zero
			-- every ten minutes.
			steam_players_peak    = CASE WHEN v.peak > 0 THEN v.peak ELSE g.steam_players_peak END,
			steam_chart_rank      = v.rank,
			steam_stats_synced_at = NOW() AT TIME ZONE 'UTC'
		FROM (VALUES `+strings.Join(tuples, ", ")+`)
			AS v(game_id, online, peak, rank)
		WHERE g.id = v.game_id`, args...)
	if err != nil {
		return 0, fmt.Errorf("update steam player counts: %w", err)
	}

	return tag.RowsAffected(), nil
}
