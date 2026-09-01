package repository

import (
	"context"
	"fmt"
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
