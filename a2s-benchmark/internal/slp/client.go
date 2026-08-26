// Package slp is the Minecraft Server List Ping (Java Edition, 1.7+).
//
// TCP handshake with next-state=1, then a status request; the server replies
// with one JSON document that carries the MOTD, version and player counts.
// Pre-1.7 servers speak the legacy 0xFE ping and are not handled here.
//
// The mirror on the LobbyHub side is MinecraftQueryDriver.php — the two
// speak the same wire format on purpose so a server that answers one
// answers the other.
package slp

import (
	"context"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"net"
	"time"

	"github.com/lobbyhub/a2s-benchmark/internal/snapshot"
)

// statusPacket is packet id 0x00 both ways in the status state: the client
// sends handshake and status request with it, the server replies with it.
const statusPacket = 0x00

// maxPayload guards against a hostile server declaring a huge length and
// making us read a quarter-megabyte per query. The real MOTD payload is a
// few kilobytes at most; anything past this is either an attack or a
// misconfigured server we shouldn't crawl.
const maxPayload = 262144

// protocolVersion is the number sent in the handshake. Any value works
// against a Java server responding to SLP — the server does not enforce a
// specific value for a status query, only for a login attempt. 47 is
// 1.8.x, safely wide.
const protocolVersion = 47

// Query performs one status request against endpoint (host:port) and
// returns a protocol-agnostic Snapshot.
//
// The passed timeout covers everything: TCP connect, both writes and both
// reads. TCP is connection-oriented, so unlike A2S a closed port fails
// fast with ECONNREFUSED rather than silently timing out.
func Query(ctx context.Context, endpoint string, timeout time.Duration) snapshot.Snapshot {
	deadline := time.Now().Add(timeout)

	if ctx.Err() != nil {
		return snapshot.Snapshot{Outcome: snapshot.OutcomeNetworkError, Err: ctx.Err()}
	}

	dialer := net.Dialer{Deadline: deadline}
	conn, err := dialer.DialContext(ctx, "tcp", endpoint)
	if err != nil {
		return snapshot.Snapshot{Outcome: snapshot.OutcomeNetworkError, Err: err}
	}
	defer conn.Close()

	if err := conn.SetDeadline(deadline); err != nil {
		return snapshot.Snapshot{Outcome: snapshot.OutcomeNetworkError, Err: err}
	}

	started := time.Now()

	// host and port for the handshake — the server checks these against
	// what it thinks it is. Using the dialed endpoint is fine even when it
	// is an IP; SLP does not enforce a hostname.
	host, port, err := splitHostPort(endpoint)
	if err != nil {
		return snapshot.Snapshot{
			Outcome: snapshot.OutcomeProtocolError,
			Err:     err,
			Latency: time.Since(started),
		}
	}

	handshake := makeHandshake(host, port)
	statusReq := makePacket(statusPacket, nil)

	if _, err := conn.Write(append(handshake, statusReq...)); err != nil {
		return classifyIOError(err, 1, time.Since(started))
	}

	length, err := readVarInt(conn)
	if err != nil {
		return classifyIOError(err, 1, time.Since(started))
	}
	if length <= 0 || length > maxPayload {
		return snapshot.Snapshot{
			Outcome: snapshot.OutcomeMalformed,
			Err:     fmt.Errorf("declared payload length out of range: %d", length),
			Packets: 1,
			Latency: time.Since(started),
		}
	}

	payload := make([]byte, length)
	if _, err := io.ReadFull(conn, payload); err != nil {
		return classifyIOError(err, 1, time.Since(started))
	}
	latency := time.Since(started)

	pkt := &reader{buf: payload}
	pid, err := pkt.varInt()
	if err != nil {
		return snapshot.Snapshot{Outcome: snapshot.OutcomeMalformed, Err: err, Packets: 1, Latency: latency}
	}
	if pid != statusPacket {
		return snapshot.Snapshot{
			Outcome: snapshot.OutcomeProtocolError,
			Err:     fmt.Errorf("unexpected packet id: 0x%02x", pid),
			Packets: 1,
			Latency: latency,
		}
	}
	jsonStr, err := pkt.string()
	if err != nil {
		return snapshot.Snapshot{Outcome: snapshot.OutcomeMalformed, Err: err, Packets: 1, Latency: latency}
	}

	info, err := parseStatus(jsonStr)
	if err != nil {
		return snapshot.Snapshot{Outcome: snapshot.OutcomeMalformed, Err: err, Packets: 1, Latency: latency}
	}

	return snapshot.Snapshot{
		Outcome: snapshot.OutcomeResponded,
		Info:    info,
		Latency: latency,
		Packets: 1,
	}
}

func splitHostPort(endpoint string) (string, uint16, error) {
	host, portStr, err := net.SplitHostPort(endpoint)
	if err != nil {
		return "", 0, err
	}
	var port int
	if _, err := fmt.Sscanf(portStr, "%d", &port); err != nil {
		return "", 0, fmt.Errorf("bad port %q: %w", portStr, err)
	}
	if port < 0 || port > 65535 {
		return "", 0, fmt.Errorf("port out of range: %d", port)
	}
	return host, uint16(port), nil
}

func makeHandshake(host string, port uint16) []byte {
	// Body: protocolVersion (VarInt) + host (String) + port (u16 BE) +
	// nextState (VarInt, 1 = status).
	body := make([]byte, 0, 8+len(host))
	body = appendVarInt(body, protocolVersion)
	body = appendString(body, host)
	body = binary.BigEndian.AppendUint16(body, port)
	body = appendVarInt(body, 1)
	return makePacket(statusPacket, body)
}

// makePacket wraps a payload as: length(body+1 for pid) VarInt + packet id
// VarInt + body bytes. Wire format shared by every SLP packet.
func makePacket(pid int32, body []byte) []byte {
	var idBuf [5]byte
	idLen := writeVarInt(idBuf[:], pid)

	length := int32(idLen + len(body))
	var lenBuf [5]byte
	lenLen := writeVarInt(lenBuf[:], length)

	out := make([]byte, 0, lenLen+idLen+len(body))
	out = append(out, lenBuf[:lenLen]...)
	out = append(out, idBuf[:idLen]...)
	out = append(out, body...)
	return out
}

func classifyIOError(err error, packets int, latency time.Duration) snapshot.Snapshot {
	var netErr net.Error
	if errors.As(err, &netErr) && netErr.Timeout() {
		return snapshot.Snapshot{Outcome: snapshot.OutcomeTimeout, Err: err, Packets: packets, Latency: latency}
	}
	return snapshot.Snapshot{Outcome: snapshot.OutcomeNetworkError, Err: err, Packets: packets, Latency: latency}
}
