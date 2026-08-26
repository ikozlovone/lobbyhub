package a2s

import (
	"context"
	"encoding/binary"
	"errors"
	"fmt"
	"net"
	"time"

	"github.com/lobbyhub/a2s-benchmark/internal/snapshot"
)

// Query performs one A2S_INFO request against the endpoint, following the
// challenge/response dance if the server asks for it.
//
// The deadline covers the whole exchange, both round trips. A server that
// answers a challenge and then times out on the second exchange counts as
// timeout, not responded — same rule the LobbyHub monitor uses.
//
// One UDP socket per call. Cheap on Linux at the concurrency the benchmark
// runs; pooling sockets would fold latency of one server into another and
// muddy the measurement this whole thing exists to make.
func Query(ctx context.Context, endpoint string, timeout time.Duration) snapshot.Snapshot {
	deadline := time.Now().Add(timeout)

	conn, err := net.Dial("udp", endpoint)
	if err != nil {
		return snapshot.Snapshot{Outcome: snapshot.OutcomeNetworkError, Err: err}
	}
	defer conn.Close()

	if err := conn.SetDeadline(deadline); err != nil {
		return snapshot.Snapshot{Outcome: snapshot.OutcomeNetworkError, Err: err}
	}

	// If the caller's context is already dead, don't bother sending anything.
	if ctx.Err() != nil {
		return snapshot.Snapshot{Outcome: snapshot.OutcomeNetworkError, Err: ctx.Err()}
	}

	started := time.Now()
	packets := 0

	// First request: no challenge. Most servers respond with S2C_CHALLENGE.
	// Some older/misconfigured ones answer directly with info; we handle both.
	req := buildInfoRequest(0, false)
	if _, err := conn.Write(req); err != nil {
		return snapshot.Snapshot{Outcome: snapshot.OutcomeNetworkError, Err: err, Packets: packets}
	}
	packets++

	buf := make([]byte, 4096)
	n, err := conn.Read(buf)
	if err != nil {
		return classifyReadError(err, packets, time.Since(started))
	}

	// Server answered directly — cheap path, one round trip.
	if !IsChallenge(buf[:n]) {
		return finishParse(buf[:n], packets, time.Since(started))
	}

	// Second request: echo the challenge.
	if n < 9 {
		return snapshot.Snapshot{
			Outcome: snapshot.OutcomeProtocolError,
			Err:     fmt.Errorf("challenge payload too short: %d bytes", n),
			Packets: packets,
			Latency: time.Since(started),
		}
	}
	challenge := ExtractChallenge(buf[:n])

	req = buildInfoRequest(challenge, true)
	if _, err := conn.Write(req); err != nil {
		return snapshot.Snapshot{Outcome: snapshot.OutcomeNetworkError, Err: err, Packets: packets, Latency: time.Since(started)}
	}
	packets++

	n, err = conn.Read(buf)
	if err != nil {
		return classifyReadError(err, packets, time.Since(started))
	}

	// A server that returned another challenge here is misbehaving — the
	// second request carried the challenge it just gave us.
	if IsChallenge(buf[:n]) {
		return snapshot.Snapshot{
			Outcome: snapshot.OutcomeProtocolError,
			Err:     errors.New("second challenge after echo"),
			Packets: packets,
			Latency: time.Since(started),
		}
	}

	return finishParse(buf[:n], packets, time.Since(started))
}

// finishParse turns a full A2S_INFO response into a Snapshot with a
// protocol-agnostic Info payload. Called from both the fast (no challenge)
// and slow (post-echo) paths so the mapping lives in one place.
func finishParse(buf []byte, packets int, latency time.Duration) snapshot.Snapshot {
	info, err := ParseInfo(buf)
	if err != nil {
		return snapshot.Snapshot{
			Outcome: snapshot.OutcomeMalformed,
			Err:     err,
			Packets: packets,
			Latency: latency,
		}
	}
	return snapshot.Snapshot{
		Outcome: snapshot.OutcomeResponded,
		Info:    toSnapshotInfo(info),
		Latency: latency,
		Packets: packets,
	}
}

// toSnapshotInfo lifts the A2S wire fields into the protocol-agnostic
// Info. Nullable fields on Info are pointers so the writer's COALESCE
// preserves the previous DB value for things this exchange did not carry
// (a very old server with no EDF block, for instance, has no game_port
// or steam_id and its previous values are what stays).
func toSnapshotInfo(info *Info) *snapshot.Info {
	maxPlayers := int(info.MaxPlayers)
	bots := int(info.Bots)
	vac := info.VAC != 0

	out := &snapshot.Info{
		PlayersOnline: int(info.Players),
		PlayersMax:    &maxPlayers,
		Bots:          &bots,
		VACEnabled:    &vac,
		Map:           info.Map,
		Version:       info.Version,
		MOTD:          info.Name,
	}
	if info.GamePort != 0 {
		gp := int(info.GamePort)
		out.GamePort = &gp
	}
	if info.SteamID != 0 {
		sid := info.SteamID
		out.SteamID = &sid
	}
	return out
}

func buildInfoRequest(challenge uint32, withChallenge bool) []byte {
	payload := []byte(InfoPayload)
	size := 4 + 1 + len(payload)
	if withChallenge {
		size += 4
	}
	buf := make([]byte, size)
	binary.LittleEndian.PutUint32(buf[0:4], HeaderSimple)
	buf[4] = A2SInfo
	copy(buf[5:], payload)
	if withChallenge {
		binary.LittleEndian.PutUint32(buf[5+len(payload):], challenge)
	}
	return buf
}

func classifyReadError(err error, packets int, latency time.Duration) snapshot.Snapshot {
	var netErr net.Error
	if errors.As(err, &netErr) && netErr.Timeout() {
		return snapshot.Snapshot{Outcome: snapshot.OutcomeTimeout, Err: err, Packets: packets, Latency: latency}
	}
	return snapshot.Snapshot{Outcome: snapshot.OutcomeNetworkError, Err: err, Packets: packets, Latency: latency}
}
