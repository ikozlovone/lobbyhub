package eos

import (
	"net"
	"strings"
	"unicode/utf8"
)

// Session is one EOS matchmaking session lifted onto the flat vocabulary the
// sweep works with — the same set of fields A2S carries in its info reply,
// plus nothing.
//
// EOS attributes come typed on the wire (`ADDRESS_s`, `GAMEPORT_l`,
// `MAPNAME_s`, …), and half of them are absent from half of the sessions.
// Everything the writer needs is decoded here and anything unknown is
// dropped, so the rest of the code touches one struct instead of a nested
// map of any's.
type Session struct {
	IP            string
	Port          int
	Name          string
	PlayersOnline int
	PlayersMax    int
	Map           string
	Version       string
	SessionID     string
}

// AddressKey is `ip:port` — the same shape sweep uses to key against local
// server rows. Cheap to build, so returning it as a string beats an extra
// struct just to carry two fields.
func (s Session) AddressKey() string {
	return s.IP + ":" + itoa(s.Port)
}

// itoa is a tiny inline int→string so the file needs no strconv import.
// Positive-only is fine because Port is bounded to 1..65535 upstream.
func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	var buf [6]byte
	i := len(buf)
	for n > 0 {
		i--
		buf[i] = byte('0' + n%10)
		n /= 10
	}
	return string(buf[i:])
}

// ParseSession lifts one raw session row into a Session, or nil if the row
// carries no usable address. The caller (sweep) drops nils rather than
// counting them; a session without an address is the same as a session that
// wasn't listed.
//
// Parsing rules match the PHP DiscoveredEosServer — same field priorities,
// same fallbacks — so a walk on either side sees the same numbers for the
// same session.
func ParseSession(raw map[string]any) *Session {
	attrs := attributes(raw)

	ip, ok := stringAttr(attrs, "ADDRESS_s")
	if !ok {
		return nil
	}
	if net.ParseIP(ip) == nil {
		return nil
	}

	port := intAttr(attrs, "GAMEPORT_l")
	if port < 1 || port > 65535 {
		return nil
	}

	// Two name candidates in ARK: SA — the human one (`CUSTOMSERVERNAME_s`)
	// wins over the manufactured session name. Fall through to the address
	// so nothing ever comes out blank.
	name, _ := stringAttr(attrs, "CUSTOMSERVERNAME_s")
	if name == "" {
		name, _ = stringAttr(attrs, "SESSIONNAME_s")
	}
	if name == "" {
		name = ip + ":" + itoa(port)
	}

	// Player counts: totalPlayers on the envelope is the current count in
	// every session I have looked at. Max is one of three shapes depending
	// on the game and the EOS SDK version.
	playersOnline := intField(raw, "totalPlayers")
	openSlots := intField(raw, "openPublicPlayers")
	playersMax := 0

	if settings, ok := raw["settings"].(map[string]any); ok {
		playersMax = intField(settings, "maxPublicPlayers")
	}
	if playersMax == 0 && (openSlots > 0 || playersOnline > 0) {
		playersMax = playersOnline + openSlots
	}

	mapName, _ := stringAttr(attrs, "MAPNAME_s")
	version, _ := stringAttr(attrs, "BUILDID_s")
	if version == "" {
		version, _ = stringAttr(attrs, "SERVERVERSION_s")
	}
	sessionID, _ := stringField(raw, "id")

	return &Session{
		IP:            ip,
		Port:          port,
		Name:          trimTo(name, 255),
		PlayersOnline: nonNeg(playersOnline),
		PlayersMax:    nonNeg(playersMax),
		Map:           trimTo(mapName, 255),
		Version:       trimTo(version, 255),
		SessionID:     sessionID,
	}
}

// attributes finds the attribute dict in either of the two envelope shapes
// Epic's regions send — top-level `attributes`, or an inner `sessionAttributes`
// fallback. Nested `attributes.attributes` (seen from EU sometimes) is
// unwrapped here so the rest of the file deals with one dictionary.
func attributes(raw map[string]any) map[string]any {
	if a, ok := raw["attributes"].(map[string]any); ok {
		if inner, ok := a["attributes"].(map[string]any); ok {
			return inner
		}
		return a
	}
	if a, ok := raw["sessionAttributes"].(map[string]any); ok {
		return a
	}
	return map[string]any{}
}

// stringAttr reads a string-typed EOS attribute (`*_s`) with the whitespace
// trim the wire tends to arrive with.
func stringAttr(m map[string]any, key string) (string, bool) {
	v, ok := m[key]
	if !ok {
		return "", false
	}
	s, ok := v.(string)
	if !ok {
		return "", false
	}
	s = strings.TrimSpace(s)
	return s, s != ""
}

// intAttr reads an integer-typed attribute (`*_l`). JSON numbers decode as
// float64 through map[string]any; the cast is what turns them into Go ints.
// Anything that isn't a number gives 0, which the caller reads as "absent".
func intAttr(m map[string]any, key string) int {
	v, ok := m[key]
	if !ok {
		return 0
	}
	switch t := v.(type) {
	case float64:
		return int(t)
	case int:
		return t
	}
	return 0
}

// intField / stringField are the same reads against the top-level session
// envelope rather than the attributes dict — kept separate because the two
// have slightly different rules (attributes are all suffixed, envelope keys
// are not).
func intField(m map[string]any, key string) int {
	return intAttr(m, key)
}

func stringField(m map[string]any, key string) (string, bool) {
	return stringAttr(m, key)
}

// trimTo caps a string at n runes (not bytes) so a UTF-8 boundary is never
// cut mid-sequence, which is what mb_substr does on the PHP side. Cheap;
// most names are already under the cap.
func trimTo(s string, n int) string {
	if utf8.RuneCountInString(s) <= n {
		return s
	}
	runes := []rune(s)
	return string(runes[:n])
}

func nonNeg(n int) int {
	if n < 0 {
		return 0
	}
	return n
}
