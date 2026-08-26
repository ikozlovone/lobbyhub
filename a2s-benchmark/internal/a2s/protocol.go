// Package a2s implements just enough of Valve's A2S protocol to send A2S_INFO
// and read the response. The benchmark uses it; a future collector will too,
// which is why the client is a component, not a script.
package a2s

// All A2S traffic starts with one of these two four-byte headers. `Simple` is
// what almost every response uses; `Multi` is the header of a split packet
// that we deliberately don't support here — a single A2S_INFO reply fits in
// one datagram, and a benchmark that pretends otherwise would be measuring
// its own reassembly code.
const (
	HeaderSimple uint32 = 0xFFFFFFFF
	HeaderMulti  uint32 = 0xFFFFFFFE
)

// Request codes we send.
const (
	A2SInfo byte = 'T'
)

// Response codes we accept.
const (
	RespInfo      byte = 'I'
	RespChallenge byte = 'A'
)

// The payload every A2S_INFO carries after its type byte — a magic string,
// null-terminated. Servers reject the packet without it.
const InfoPayload = "Source Engine Query\x00"

// Extra Data Flags. Each bit tells us the corresponding field follows the
// version string. We only read `game_port` and `steam_id` for validation;
// the rest are skipped over the wire.
const (
	EDFPort      byte = 0x80
	EDFSteamID   byte = 0x10
	EDFSpectator byte = 0x40
	EDFKeywords  byte = 0x20
	EDFGameID    byte = 0x01
)

// The Ship reports three extra bytes right after `vac`. It is the only
// current game that does; the branch exists because omitting it would offset
// every field after it and read garbage for the version string.
const AppIDTheShip = 2400
