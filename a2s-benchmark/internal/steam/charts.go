// Package steam reads player counts from Valve's own public endpoints.
//
// Two of them, and the difference between them is the whole design:
//
//   - ISteamChartsService/GetGamesByConcurrentPlayers returns the official top
//     100 in one request — rank, appid, players now, peak today. One call
//     covers a hundred games, so every charted game is free.
//   - ISteamUserStats/GetNumberOfCurrentPlayers answers for one appid and
//     carries neither rank nor peak. It is what the games below the top 100
//     cost: one request each.
//
// Neither needs an API key, and neither has anything to do with the Steam
// master server. The master lists dedicated servers with addresses; these
// count people in a game, including single-player and matchmaking. A game can
// be second on Steam by players with no servers to list at all.
package steam

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"strconv"
	"time"
)

const (
	chartURL  = "https://api.steampowered.com/ISteamChartsService/GetGamesByConcurrentPlayers/v1/"
	playerURL = "https://api.steampowered.com/ISteamUserStats/GetNumberOfCurrentPlayers/v1/"
)

// errNoSuchApp is what a 404 from the player-count endpoint means: Valve has
// no such app, or none it will answer for. Measured, not assumed — an appid
// that does not exist comes back 404 rather than with the `result != 1` the
// endpoint documents, and a catalog row with a wrong appid must not turn every
// ten-minute run into a failure and a cron mail.
var errNoSuchApp = errors.New("no such app")

// Client talks to Valve. The zero value is not usable; call New.
type Client struct {
	http *http.Client
}

// New returns a client whose requests share one connection pool — the poll of
// the games below the chart is dozens of requests to one host, and opening a
// TLS session for each of them is most of the time they would take.
func New(timeout time.Duration) *Client {
	return &Client{http: &http.Client{Timeout: timeout}}
}

// Entry is one game's line in the official chart.
type Entry struct {
	Rank      uint16
	AppID     uint32
	Players   uint32
	PeakToday uint32
}

// Chart returns the official top 100, in rank order.
//
// The ranking is Valve's own — it is what steamdb.com and every other charts
// page republishes — so there is nothing to scrape and nobody in between to
// disagree with.
func (c *Client) Chart(ctx context.Context) ([]Entry, error) {
	var payload struct {
		Response struct {
			LastUpdate int64 `json:"last_update"`
			Ranks      []struct {
				Rank             uint16 `json:"rank"`
				AppID            uint32 `json:"appid"`
				ConcurrentInGame uint32 `json:"concurrent_in_game"`
				PeakInGame       uint32 `json:"peak_in_game"`
			} `json:"ranks"`
		} `json:"response"`
	}

	if err := c.get(ctx, chartURL, nil, &payload); err != nil {
		return nil, fmt.Errorf("steam chart: %w", err)
	}

	out := make([]Entry, 0, len(payload.Response.Ranks))
	for _, r := range payload.Response.Ranks {
		out = append(out, Entry{
			Rank:      r.Rank,
			AppID:     r.AppID,
			Players:   r.ConcurrentInGame,
			PeakToday: r.PeakInGame,
		})
	}
	return out, nil
}

// PlayerCount answers for one game, charted or not.
//
// Two ways to be told there is no number, and both are answers rather than
// failures: a 404, which is what an appid Valve does not know comes back as,
// and `result != 1`, the documented "this app publishes no count" — a tool, or
// something unreleased. Both return (0, false, nil) and the caller records
// nothing. A real failure — a timeout, a 500, a body that will not parse —
// still comes back as an error.
func (c *Client) PlayerCount(ctx context.Context, appID uint32) (uint32, bool, error) {
	var payload struct {
		Response struct {
			PlayerCount uint32 `json:"player_count"`
			Result      int    `json:"result"`
		} `json:"response"`
	}

	query := url.Values{"appid": []string{strconv.FormatUint(uint64(appID), 10)}}

	if err := c.get(ctx, playerURL, query, &payload); err != nil {
		if errors.Is(err, errNoSuchApp) {
			return 0, false, nil
		}
		return 0, false, fmt.Errorf("player count for %d: %w", appID, err)
	}
	if payload.Response.Result != 1 {
		return 0, false, nil
	}
	return payload.Response.PlayerCount, true, nil
}

func (c *Client) get(ctx context.Context, endpoint string, query url.Values, into any) error {
	target := endpoint
	if len(query) > 0 {
		target += "?" + query.Encode()
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, target, nil)
	if err != nil {
		return fmt.Errorf("build request: %w", err)
	}
	// Named honestly, the way the PHP side names itself to gamemonitoring.
	req.Header.Set("User-Agent", "LobbyHub/1.0 (+https://lobbyhub.gg)")
	req.Header.Set("Accept", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusNotFound {
		return errNoSuchApp
	}
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("http %d", resp.StatusCode)
	}

	if err := json.NewDecoder(resp.Body).Decode(into); err != nil {
		return fmt.Errorf("decode: %w", err)
	}
	return nil
}
