package slp

import (
	"errors"
	"fmt"
	"io"
)

// VarInt is Minecraft's variable-length integer: 7 data bits per byte, MSB
// signals continuation. Same encoding Protocol Buffers uses. Capped at 5
// bytes for 32-bit ints; a longer sequence is either a bug or an attack.

var errVarIntTooLong = errors.New("VarInt is longer than 5 bytes")

func writeVarInt(dst []byte, v int32) int {
	uv := uint32(v)
	n := 0
	for {
		b := byte(uv & 0x7F)
		uv >>= 7
		if uv != 0 {
			b |= 0x80
		}
		dst[n] = b
		n++
		if uv == 0 {
			return n
		}
	}
}

func appendVarInt(dst []byte, v int32) []byte {
	var buf [5]byte
	n := writeVarInt(buf[:], v)
	return append(dst, buf[:n]...)
}

func appendString(dst []byte, s string) []byte {
	dst = appendVarInt(dst, int32(len(s)))
	return append(dst, s...)
}

// readVarInt pulls a VarInt off an io.Reader one byte at a time. Only used
// once per query (for the outer payload length), so the extra syscall of
// per-byte reads does not matter.
func readVarInt(r io.Reader) (int32, error) {
	var buf [1]byte
	var result int32
	for i := 0; i < 5; i++ {
		if _, err := io.ReadFull(r, buf[:]); err != nil {
			return 0, err
		}
		b := buf[0]
		result |= int32(b&0x7F) << (7 * i)
		if b&0x80 == 0 {
			return result, nil
		}
	}
	return 0, errVarIntTooLong
}

// reader parses a byte buffer as sequential SLP fields — the shape of the
// inner status packet after we've read its length off the wire.
type reader struct {
	buf []byte
	pos int
}

func (r *reader) varInt() (int32, error) {
	var result int32
	for i := 0; i < 5; i++ {
		if r.pos >= len(r.buf) {
			return 0, io.ErrUnexpectedEOF
		}
		b := r.buf[r.pos]
		r.pos++
		result |= int32(b&0x7F) << (7 * i)
		if b&0x80 == 0 {
			return result, nil
		}
	}
	return 0, errVarIntTooLong
}

func (r *reader) string() (string, error) {
	length, err := r.varInt()
	if err != nil {
		return "", err
	}
	if length < 0 || int(length) > len(r.buf)-r.pos {
		return "", fmt.Errorf("string length %d out of range", length)
	}
	s := string(r.buf[r.pos : r.pos+int(length)])
	r.pos += int(length)
	return s, nil
}
