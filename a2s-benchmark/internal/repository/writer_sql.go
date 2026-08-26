package repository

import (
	"strconv"
	"strings"

	"github.com/lobbyhub/a2s-benchmark/internal/snapshot"
)

// One VALUES tuple per update, in this order. Both callers rely on the
// order — keep them in step.
const columnsPerRow = 12

// buildUpdate assembles one statement that writes every row in the batch.
//
// $1 is game_id (constant for the whole sweep — one query, one partition).
// Rows follow at $2, in blocks of columnsPerRow.
//
// COALESCE / NULLIF guard the columns a driver may not report — a Minecraft
// server has no map, an old Source build has no VAC byte — so a NULL bind
// leaves the old value in place rather than blanking it. Two CASE branches
// fold the online and offline paths into one statement, which is why
// `status` drives every column that differs between them.
//
// Postgres cannot infer the type of a bare placeholder in a VALUES list and
// says so; casts go on the first row and the rest inherit them. Same trick
// SteamCatalogSync uses.
func buildUpdate(gameID int64, batch []StateUpdate) (string, []any) {
	if len(batch) == 0 {
		return "", nil
	}

	args := make([]any, 0, 1+len(batch)*columnsPerRow)
	args = append(args, gameID)

	tuples := make([]string, 0, len(batch))
	next := 2

	for i, u := range batch {
		tuples = append(tuples, renderTuple(next, i == 0))
		next += columnsPerRow
		args = append(args, valuesFor(u)...)
	}

	sql := `UPDATE server_states AS s SET
  status               = v.status,
  players_online       = v.players_online,
  players_max          = COALESCE(v.players_max, s.players_max),
  bots                 = COALESCE(v.bots, s.bots),
  vac_enabled          = COALESCE(v.vac_enabled, s.vac_enabled),
  map                  = COALESCE(NULLIF(v.map, ''), s.map),
  reported_version     = COALESCE(NULLIF(v.reported_version, ''), s.reported_version),
  motd                 = COALESCE(NULLIF(v.motd, ''), s.motd),
  game_port            = COALESCE(v.game_port, s.game_port),
  steam_id             = COALESCE(NULLIF(v.steam_id, ''), s.steam_id),
  last_queried_at      = v.queried_at,
  last_online_at       = CASE WHEN v.status = 'online'  THEN v.queried_at ELSE s.last_online_at  END,
  last_offline_at      = CASE WHEN v.status = 'offline' THEN v.queried_at ELSE s.last_offline_at END,
  failed_queries_count = CASE
    WHEN v.status = 'online' THEN 0
    ELSE LEAST(s.failed_queries_count + 1, 65535)
  END,
  updated_at           = v.queried_at
FROM (VALUES ` + strings.Join(tuples, ", ") + `)
  AS v(server_id, status, players_online, players_max, bots, vac_enabled,
       map, reported_version, motd, game_port, steam_id, queried_at)
WHERE s.server_id = v.server_id AND s.game_id = $1`

	return sql, args
}

// renderTuple builds `($N::bigint, $N+1::text, ...)` for the first row and
// bare `($N, $N+1, ...)` for the rest. Casts only on the first because
// Postgres reads the tuple shape from it.
func renderTuple(start int, withCasts bool) string {
	casts := [columnsPerRow]string{
		"::bigint",    // server_id
		"::text",      // status
		"::int",       // players_online
		"::int",       // players_max
		"::smallint",  // bots
		"::boolean",   // vac_enabled
		"::text",      // map
		"::text",      // reported_version
		"::text",      // motd
		"::int",       // game_port
		"::text",      // steam_id
		"::timestamp", // queried_at
	}
	parts := make([]string, columnsPerRow)
	for i := 0; i < columnsPerRow; i++ {
		p := "$" + strconv.Itoa(start+i)
		if withCasts {
			p += casts[i]
		}
		parts[i] = p
	}
	return "(" + strings.Join(parts, ", ") + ")"
}

// valuesFor turns one queued result into the twelve VALUES bind params.
//
// Nullable columns are *pointers* so pgx sends SQL NULL and the COALESCE
// in the statement keeps the previous value. Zero-valued numerics off a
// live info block (game_port = 0, steam_id = 0) become NULL for the same
// reason — a server that didn't publish them shouldn't overwrite what we
// already had.
//
// Text fields (name, map, version) go through ToValidUTF8 first: A2S
// gives them to us as raw bytes and a fair share of CS2 owners publish
// names in cp1251, chop emoji mid-sequence, or ship outright garbage.
// Postgres stores `text` as UTF-8 and rejects the whole batch on the
// first invalid byte (SQLSTATE 22021), so one broken server would kill
// the other four hundred and ninety-nine in its batch. Stripping the
// bad bytes reduces to empty when the whole string is junk — the NULLIF
// in the statement then keeps whatever we had before.
func valuesFor(u StateUpdate) []any {
	if u.Snapshot.Outcome == snapshot.OutcomeResponded && u.Snapshot.Info != nil {
		info := u.Snapshot.Info
		return []any{
			u.ServerID,
			"online",
			info.PlayersOnline,
			ptrIntFrom(info.PlayersMax),
			ptrInt16From(info.Bots),
			info.VACEnabled,
			ptrIfNonEmpty(strings.ToValidUTF8(info.Map, "")),
			ptrIfNonEmpty(strings.ToValidUTF8(info.Version, "")),
			ptrIfNonEmpty(strings.ToValidUTF8(info.MOTD, "")),
			ptrIntFrom(info.GamePort),
			ptrSteamIDFrom(info.SteamID),
			u.QueriedAt,
		}
	}
	// Offline (timeout / network / protocol / malformed): status flips,
	// players_online goes to zero, everything else is NULL so COALESCE
	// keeps what we last saw.
	return []any{
		u.ServerID,
		"offline",
		0,
		(*int)(nil),
		(*int16)(nil),
		(*bool)(nil),
		(*string)(nil),
		(*string)(nil),
		(*string)(nil),
		(*int)(nil),
		(*string)(nil),
		u.QueriedAt,
	}
}

// ptrIntFrom passes an *int straight through — nil stays nil so pgx binds
// SQL NULL and COALESCE keeps the DB value.
func ptrIntFrom(v *int) *int { return v }

// ptrInt16From narrows *int (from snapshot.Info) into the smallint the
// bots column expects. Silent clamp — the wire value is a byte so the cast
// is always exact.
func ptrInt16From(v *int) *int16 {
	if v == nil {
		return nil
	}
	x := int16(*v)
	return &x
}

func ptrIfNonEmpty(s string) *string {
	if s == "" {
		return nil
	}
	return &s
}

// ptrSteamIDFrom formats a Steam ID into the decimal string the varchar(24)
// column stores. Nil in, nil out.
func ptrSteamIDFrom(v *uint64) *string {
	if v == nil {
		return nil
	}
	s := strconv.FormatUint(*v, 10)
	return &s
}

