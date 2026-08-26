package slp

import (
	"encoding/json"
	"errors"
	"regexp"
	"strings"

	"github.com/lobbyhub/a2s-benchmark/internal/snapshot"
)

// statusResponse mirrors the top-level JSON: only the fields we care about.
// version.name is a string; description is one of a bare string, an object
// with "text" and optional "extra", or an array of those recursively.
type statusResponse struct {
	Version struct {
		Name string `json:"name"`
	} `json:"version"`
	Players struct {
		Online int `json:"online"`
		Max    int `json:"max"`
	} `json:"players"`
	Description json.RawMessage `json:"description"`
}

// motdCode matches Minecraft's § colour and formatting codes, e.g. §c, §l,
// §k, §r. The MOTD column stores plain text; the codes are visual noise
// for a search/index.
var motdCode = regexp.MustCompile(`(?i)§[0-9a-fk-or]`)

// motdWhitespace collapses runs of any whitespace (including bare \n from
// centred MOTDs) into single spaces so the stored column reads as one line.
var motdWhitespace = regexp.MustCompile(`\s+`)

// motdLimit is what MinecraftQueryDriver on the PHP side truncates to —
// server_states.motd is text, so this is presentational rather than a
// storage limit. Kept in sync so the two paths write the same field length.
const motdLimit = 512

// versionLimit tracks reported_version varchar(255) in the schema.
const versionLimit = 255

func parseStatus(body string) (*snapshot.Info, error) {
	if body == "" {
		return nil, errors.New("empty status body")
	}

	var s statusResponse
	if err := json.Unmarshal([]byte(body), &s); err != nil {
		return nil, err
	}

	// Servers that hide their player count report -1 in `online`.
	online := s.Players.Online
	if online < 0 {
		online = 0
	}
	max := s.Players.Max
	if max < 0 {
		max = 0
	}

	info := &snapshot.Info{
		PlayersOnline: online,
		PlayersMax:    &max,
	}

	if v := strings.TrimSpace(s.Version.Name); v != "" {
		info.Version = truncate(v, versionLimit)
	}

	if motd := flattenDescription(s.Description); motd != "" {
		info.MOTD = truncate(motd, motdLimit)
	}

	return info, nil
}

// flattenDescription turns the chat component tree into one line of plain
// text. The tree is either a bare string, an object with "text" and an
// optional "extra" array, or an array of any of those recursively. The
// server may also nest anything under "extra"; MinecraftQueryDriver.php
// handles the same shape.
func flattenDescription(raw json.RawMessage) string {
	if len(raw) == 0 {
		return ""
	}

	var out strings.Builder
	appendComponent(&out, raw)

	text := out.String()
	text = motdCode.ReplaceAllString(text, "")
	text = motdWhitespace.ReplaceAllString(text, " ")
	return strings.TrimSpace(text)
}

func appendComponent(out *strings.Builder, raw json.RawMessage) {
	// Bare string.
	var s string
	if err := json.Unmarshal(raw, &s); err == nil {
		out.WriteString(s)
		return
	}

	// Array of components.
	var arr []json.RawMessage
	if err := json.Unmarshal(raw, &arr); err == nil {
		for _, part := range arr {
			appendComponent(out, part)
		}
		return
	}

	// Object with optional "text" and optional "extra".
	var obj struct {
		Text  string            `json:"text"`
		Extra []json.RawMessage `json:"extra"`
	}
	if err := json.Unmarshal(raw, &obj); err == nil {
		if obj.Text != "" {
			out.WriteString(obj.Text)
		}
		for _, part := range obj.Extra {
			appendComponent(out, part)
		}
	}
}

// truncate cuts a string to n bytes at a rune boundary, so multibyte MOTDs
// (Chinese servers publish plenty) do not end mid-character and produce
// invalid UTF-8 that Postgres would reject.
func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	// Walk backwards from n to find a rune boundary. Every UTF-8 continuation
	// byte starts with 10xxxxxx, so we can stop at the first byte that does
	// not.
	for i := n; i > 0; i-- {
		if s[i]&0xC0 != 0x80 {
			return s[:i]
		}
	}
	return s[:n]
}
