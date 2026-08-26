// Package repository is the door into LobbyHub's postgres.
//
// LoadForGame selects; the optional Writer (see writer.go) UPDATEs one
// column set on `server_states`. Nothing else is written: rank recompute,
// stat samples, name adoption and scheduling stay with the PHP side.
package repository

import (
	"context"
	"errors"
	"fmt"

	"github.com/jackc/pgx/v5/pgxpool"
)

// Protocol is what wire format a server speaks. The value mirrors the
// games.query_protocol column so an operator can predict which client the
// runner will pick from the game slug alone.
type Protocol string

const (
	ProtocolSource    Protocol = "source"    // Valve A2S over UDP
	ProtocolMinecraft Protocol = "minecraft" // SLP over TCP
	ProtocolFiveM     Protocol = "fivem"     // HTTP; not implemented
)

// Server is a candidate for the sweep. Endpoint is "ip:port" ready for
// Dial; Protocol tells the runner which client to use.
//
// The port already reflects the protocol: `query_port` for Source (which
// has a separate query socket by convention), `query_port ?? port` for
// Minecraft where the SLP listener is normally on the same port as the
// game itself. LoadForGame does that mapping so the runner does not need
// to know either fact.
type Server struct {
	ID       int64
	Endpoint string
	Protocol Protocol
}

// LoadCounts is what the summary at the top of the run reports.
type LoadCounts struct {
	Total            int
	Valid            int
	MissingQueryPort int
	MissingIP        int
	UnknownProtocol  int
}

// Repo opens a pgxpool and hands out server lists. Close when done.
type Repo struct {
	pool *pgxpool.Pool
}

func Open(ctx context.Context, dsn string) (*Repo, error) {
	if dsn == "" {
		return nil, errors.New("empty DSN")
	}
	cfg, err := pgxpool.ParseConfig(dsn)
	if err != nil {
		return nil, fmt.Errorf("parse DSN: %w", err)
	}
	// One connection for the initial LoadForGame, a few more for the writer's
	// sequential batch flushes. The writer holds one at a time; the extras
	// are headroom for a second flusher if we ever add one, and for pgxpool's
	// own health-check probes.
	cfg.MaxConns = 4

	pool, err := pgxpool.NewWithConfig(ctx, cfg)
	if err != nil {
		return nil, fmt.Errorf("open pool: %w", err)
	}
	return &Repo{pool: pool}, nil
}

func (r *Repo) Close() {
	r.pool.Close()
}

// GameInfo is what LoadForGame decides at the top of a sweep: the numeric
// key for partition routing, the presentation name for the header, the
// slug that named the game on the CLI (or would if it had been named),
// and the wire protocol that picks which client the runner uses.
type GameInfo struct {
	ID       int64
	Slug     string
	Name     string
	Protocol Protocol
}

// LookupGame reads one game row so main can print the name and dispatch
// the right client. Separate call so a bad slug fails before any server
// rows are read.
func (r *Repo) LookupGame(ctx context.Context, slug string) (GameInfo, error) {
	g := GameInfo{Slug: slug}
	var proto string
	err := r.pool.QueryRow(ctx,
		`SELECT id, name, query_protocol FROM games WHERE slug = $1`, slug,
	).Scan(&g.ID, &g.Name, &proto)
	if err != nil {
		return g, fmt.Errorf("no game with slug %q: %w", slug, err)
	}
	g.Protocol = Protocol(proto)
	return g, nil
}

// ListGames returns every active game the runner knows how to probe.
// Ordered by id so a full-catalog sweep hits partitions in a stable
// sequence and can be re-run reproducibly.
//
// Protocols the runner does not implement (fivem today) are filtered
// here rather than in the loop, so the "unknown protocol skipped" line
// in the summary is what the sweep really saw, not what we asked for.
func (r *Repo) ListGames(ctx context.Context) ([]GameInfo, error) {
	rows, err := r.pool.Query(ctx,
		`SELECT id, slug, name, query_protocol
		 FROM games
		 WHERE is_active = true
		   AND query_protocol IN ('source', 'minecraft')
		 ORDER BY id`)
	if err != nil {
		return nil, fmt.Errorf("list games: %w", err)
	}
	defer rows.Close()

	var out []GameInfo
	for rows.Next() {
		var g GameInfo
		var proto string
		if err := rows.Scan(&g.ID, &g.Slug, &g.Name, &proto); err != nil {
			return nil, fmt.Errorf("scan game: %w", err)
		}
		g.Protocol = Protocol(proto)
		out = append(out, g)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("iterate games: %w", err)
	}
	return out, nil
}

// LoadForGame returns every server the given game has that is dialable,
// plus counts of the ones that were not. The endpoint on each Server is
// already the right host:port for the game's protocol.
//
// Port fallback is protocol-specific. Source servers publish a dedicated
// `query_port` and skipping the row without one is correct — the game
// port is unlikely to answer A2S. Minecraft servers rarely fill
// `query_port` because the SLP listener is on the same port as the game;
// for them the fallback to `port` is what makes the sweep non-empty.
//
// `deleted_at IS NULL` because a soft-deleted server is not something we
// own the right to poll.
func (r *Repo) LoadForGame(ctx context.Context, game GameInfo) ([]Server, LoadCounts, error) {
	var counts LoadCounts

	rows, err := r.pool.Query(ctx,
		`SELECT id, ip_address, query_port, port
		 FROM servers
		 WHERE game_id = $1 AND deleted_at IS NULL`,
		game.ID)
	if err != nil {
		return nil, counts, fmt.Errorf("query servers: %w", err)
	}
	defer rows.Close()

	var servers []Server
	for rows.Next() {
		var (
			id        int64
			ip        *string
			queryPort *int
			port      *int
		)
		if err := rows.Scan(&id, &ip, &queryPort, &port); err != nil {
			return nil, counts, fmt.Errorf("scan row: %w", err)
		}
		counts.Total++

		effectivePort := pickPort(game.Protocol, queryPort, port)
		if effectivePort == 0 {
			counts.MissingQueryPort++
			continue
		}
		if ip == nil || *ip == "" {
			counts.MissingIP++
			continue
		}

		servers = append(servers, Server{
			ID:       id,
			Endpoint: fmt.Sprintf("%s:%d", *ip, effectivePort),
			Protocol: game.Protocol,
		})
		counts.Valid++
	}
	if err := rows.Err(); err != nil {
		return nil, counts, fmt.Errorf("iterate rows: %w", err)
	}

	if game.Protocol != ProtocolSource && game.Protocol != ProtocolMinecraft {
		counts.UnknownProtocol = counts.Valid
		counts.Valid = 0
		servers = nil
	}

	return servers, counts, nil
}

// UpdateGameCounters writes the two counters our sweep can produce for
// free plus a fresh stats_synced_at. Deliberately does not touch
// servers_count — that column counts every "verified" server (including
// offline ones from prior sweeps we may have skipped this round), and
// CatalogCounters::refresh remains the source of truth for it.
//
// Called at the end of a per-game sweep when --write is set. One UPDATE
// on games.id, no aggregate scan.
func (r *Repo) UpdateGameCounters(ctx context.Context, gameID int64, onlineServers int64, playersOnline int64) error {
	// NOW() AT TIME ZONE 'UTC' rather than a Go-side timestamp so the
	// clock is the one column consumers read against, not the sweeper's
	// host clock — same rationale as the writer setting last_queried_at.
	_, err := r.pool.Exec(ctx,
		`UPDATE games
		    SET online_servers_count = $1,
		        players_online       = $2,
		        stats_synced_at      = NOW() AT TIME ZONE 'UTC'
		  WHERE id = $3`,
		onlineServers, playersOnline, gameID)
	if err != nil {
		return fmt.Errorf("update games counters: %w", err)
	}
	return nil
}

// pickPort applies the protocol's port convention.
//
// Source always uses query_port; there is no fallback because the game
// port is usually a different socket that does not speak A2S. Minecraft
// uses query_port when set (some operators configure a distinct one) and
// falls back to the game port, which is where SLP normally listens.
func pickPort(p Protocol, queryPort, port *int) int {
	switch p {
	case ProtocolSource:
		if queryPort != nil {
			return *queryPort
		}
		return 0
	case ProtocolMinecraft:
		if queryPort != nil {
			return *queryPort
		}
		if port != nil {
			return *port
		}
		return 0
	}
	return 0
}
