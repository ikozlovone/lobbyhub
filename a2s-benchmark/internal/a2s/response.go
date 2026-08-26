package a2s

import (
	"encoding/binary"
	"errors"
	"fmt"
)

// Info is the subset of A2S_INFO we parse. The benchmark only needs to prove
// the response was well-formed; the fields are kept so a future collector can
// use the same parser without reshaping it.
type Info struct {
	Protocol    byte
	Name        string
	Map         string
	Folder      string
	Game        string
	AppID       uint16
	Players     byte
	MaxPlayers  byte
	Bots        byte
	ServerType  byte
	Environment byte
	Visibility  byte
	VAC         byte
	Version     string
	GamePort    uint16 // 0 if EDF didn't carry it
	SteamID     uint64 // 0 if EDF didn't carry it
	Keywords    string
}

// Errors the parser returns. They're typed rather than sentinel strings so a
// caller can distinguish "the packet was mangled" from "the server refused"
// without matching text.
var (
	ErrShortPacket       = errors.New("packet too short")
	ErrSplitPacket       = errors.New("split packet unsupported")
	ErrUnexpectedHeader  = errors.New("unexpected header")
	ErrUnexpectedType    = errors.New("unexpected response type")
	ErrTruncatedString   = errors.New("truncated string")
)

// IsChallenge reports whether the datagram is a challenge response (S2C_CHALLENGE).
// The client uses this to decide whether to re-send with the returned key.
func IsChallenge(p []byte) bool {
	return len(p) >= 5 &&
		binary.LittleEndian.Uint32(p[0:4]) == HeaderSimple &&
		p[4] == RespChallenge
}

// ExtractChallenge pulls the 4-byte challenge value out of an S2C_CHALLENGE
// datagram. The caller has already verified it via IsChallenge.
func ExtractChallenge(p []byte) uint32 {
	return binary.LittleEndian.Uint32(p[5:9])
}

// ParseInfo reads a full A2S_INFO response. Not the challenge — that goes
// through IsChallenge separately.
//
// Fails loudly rather than returning half-parsed data: a benchmark that treats
// malformed responses as successes reports a rate it does not have.
func ParseInfo(p []byte) (*Info, error) {
	if len(p) < 6 {
		return nil, ErrShortPacket
	}
	header := binary.LittleEndian.Uint32(p[0:4])
	if header == HeaderMulti {
		return nil, ErrSplitPacket
	}
	if header != HeaderSimple {
		return nil, ErrUnexpectedHeader
	}
	if p[4] != RespInfo {
		return nil, fmt.Errorf("%w: 0x%02x", ErrUnexpectedType, p[4])
	}

	r := &reader{buf: p, pos: 5}
	info := &Info{}

	info.Protocol = r.byte()
	info.Name = r.string()
	info.Map = r.string()
	info.Folder = r.string()
	info.Game = r.string()
	info.AppID = r.short()
	info.Players = r.byte()
	info.MaxPlayers = r.byte()
	info.Bots = r.byte()
	info.ServerType = r.byte()
	info.Environment = r.byte()
	info.Visibility = r.byte()
	info.VAC = r.byte()

	// The Ship's three extra bytes (mode, witnesses, duration) sit right
	// before the version string. Every other appid skips this branch.
	if info.AppID == AppIDTheShip {
		r.skip(3)
	}

	info.Version = r.string()

	// Optional EDF block. Servers may omit it entirely (very old builds), so
	// running out of bytes here is not an error.
	if !r.done() {
		flags := r.byte()
		if flags&EDFPort != 0 {
			info.GamePort = r.short()
		}
		if flags&EDFSteamID != 0 {
			info.SteamID = r.uint64()
		}
		if flags&EDFSpectator != 0 {
			r.short()    // spectator port
			r.string()   // spectator name
		}
		if flags&EDFKeywords != 0 {
			info.Keywords = r.string()
		}
		if flags&EDFGameID != 0 {
			r.uint64() // 64-bit game id (mostly zero)
		}
	}

	if r.err != nil {
		return nil, r.err
	}
	return info, nil
}

// A tiny byte reader that remembers the first error and keeps going. Chained
// calls on a broken buffer produce zero values; the error is checked once at
// the end. Same shape as the PHP ByteReader that ships with the monitor.
type reader struct {
	buf []byte
	pos int
	err error
}

func (r *reader) byte() byte {
	if r.err != nil || r.pos >= len(r.buf) {
		r.fail(ErrShortPacket)
		return 0
	}
	b := r.buf[r.pos]
	r.pos++
	return b
}

func (r *reader) short() uint16 {
	if r.err != nil || r.pos+2 > len(r.buf) {
		r.fail(ErrShortPacket)
		return 0
	}
	v := binary.LittleEndian.Uint16(r.buf[r.pos : r.pos+2])
	r.pos += 2
	return v
}

func (r *reader) uint64() uint64 {
	if r.err != nil || r.pos+8 > len(r.buf) {
		r.fail(ErrShortPacket)
		return 0
	}
	v := binary.LittleEndian.Uint64(r.buf[r.pos : r.pos+8])
	r.pos += 8
	return v
}

func (r *reader) string() string {
	if r.err != nil {
		return ""
	}
	start := r.pos
	for r.pos < len(r.buf) {
		if r.buf[r.pos] == 0 {
			s := string(r.buf[start:r.pos])
			r.pos++
			return s
		}
		r.pos++
	}
	r.fail(ErrTruncatedString)
	return ""
}

func (r *reader) skip(n int) {
	if r.err != nil {
		return
	}
	if r.pos+n > len(r.buf) {
		r.fail(ErrShortPacket)
		return
	}
	r.pos += n
}

func (r *reader) done() bool {
	return r.err != nil || r.pos >= len(r.buf)
}

func (r *reader) fail(err error) {
	if r.err == nil {
		r.err = err
	}
}
