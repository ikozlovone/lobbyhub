// Package snapshot is what a query produced, without saying which protocol
// produced it. Both a2s and slp return one of these; the benchmark counters
// and the state writer both take one.
//
// The point of the split is fields. A2S has a `bots` byte and a VAC bit;
// SLP does not. SLP has a version *name* that A2S has a version *string* in
// a different place. Rather than pretend one shape covers both, Info uses
// pointers for anything a protocol may not carry, and the writer's COALESCE
// leaves the previous column alone when the pointer is nil.
package snapshot

import "time"

// Outcome buckets a query into the categories the benchmark counts.
// Anything wrong falls into exactly one; the summary at the end lists them
// separately so timeouts are not conflated with malformed responses.
type Outcome int

const (
	OutcomeResponded Outcome = iota
	OutcomeTimeout
	OutcomeNetworkError
	// OutcomeProtocolError covers A2S "challenge went wrong" and SLP
	// "handshake was rejected" — a listener replied, but not the way we
	// asked it to. Distinct from OutcomeMalformed, which is a shaped
	// response the parser could not read.
	OutcomeProtocolError
	OutcomeMalformed
)

func (o Outcome) String() string {
	switch o {
	case OutcomeResponded:
		return "responded"
	case OutcomeTimeout:
		return "timeout"
	case OutcomeNetworkError:
		return "network_error"
	case OutcomeProtocolError:
		return "protocol_error"
	case OutcomeMalformed:
		return "malformed"
	default:
		return "unknown"
	}
}

// Snapshot is what one Query call returns. Info is nil unless Outcome is
// OutcomeResponded; on every other outcome the caller cares only about
// Outcome + Err + Latency + Packets for the benchmark stats.
type Snapshot struct {
	Outcome Outcome
	Info    *Info
	Latency time.Duration
	Packets int   // network packets/RTTs so the challenge round is visible
	Err     error // set for every non-Responded outcome
}

// Info is what a protocol managed to report about a server.
//
// PlayersOnline is a bare int because both protocols always report it on
// success. Everything else is a pointer or a string that is empty when the
// protocol did not carry it — the writer treats nil / "" as "leave the DB
// column alone" via COALESCE.
type Info struct {
	PlayersOnline int
	PlayersMax    *int
	Bots          *int   // A2S only
	VACEnabled    *bool  // A2S only
	Map           string // A2S only; SLP has no map concept
	Version       string
	MOTD          string // A2S "Name" field, SLP "description" chat tree
	GamePort      *int   // A2S EDF only
	SteamID       *uint64
}
