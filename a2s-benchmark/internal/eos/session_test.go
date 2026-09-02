package eos

import "testing"

// The shape gamemonitoring's ARK: SA sample publishes — trimmed to the
// fields ParseSession actually reads, plus a couple of noise ones so a
// change to unrelated defaults does not silently affect this test.
func sampleSession() map[string]any {
	return map[string]any{
		"id":                 "383cd12bb52042f3aeddbd537e7edacd",
		"totalPlayers":       float64(45),
		"openPublicPlayers":  float64(25),
		"started":            true,
		"attributes": map[string]any{
			"ADDRESS_s":          "5.62.114.92",
			"GAMEPORT_l":         float64(7779),
			"MAPNAME_s":          "Astraeos_WP",
			"CUSTOMSERVERNAME_s": "NA-PVP-Astraeos2575",
			"SESSIONNAME_s":      "session-manufactured",
			"BUILDID_s":          "93.12",
		},
	}
}

func TestParseSession_liftsARKFields(t *testing.T) {
	s := ParseSession(sampleSession())
	if s == nil {
		t.Fatal("ParseSession returned nil for a valid ARK session")
	}
	if s.IP != "5.62.114.92" || s.Port != 7779 {
		t.Errorf("address = %s:%d, want 5.62.114.92:7779", s.IP, s.Port)
	}
	// CUSTOMSERVERNAME_s wins over SESSIONNAME_s.
	if s.Name != "NA-PVP-Astraeos2575" {
		t.Errorf("name = %q, want NA-PVP-Astraeos2575", s.Name)
	}
	if s.PlayersOnline != 45 {
		t.Errorf("playersOnline = %d, want 45", s.PlayersOnline)
	}
	// Max derived from totalPlayers + openPublicPlayers when settings has no cap.
	if s.PlayersMax != 70 {
		t.Errorf("playersMax = %d, want 70", s.PlayersMax)
	}
	if s.Map != "Astraeos_WP" {
		t.Errorf("map = %q, want Astraeos_WP", s.Map)
	}
	if s.Version != "93.12" {
		t.Errorf("version = %q, want 93.12", s.Version)
	}
	if s.AddressKey() != "5.62.114.92:7779" {
		t.Errorf("addressKey = %q, want 5.62.114.92:7779", s.AddressKey())
	}
}

func TestParseSession_prefersSettingsMaxOverOpenSlots(t *testing.T) {
	raw := sampleSession()
	raw["settings"] = map[string]any{"maxPublicPlayers": float64(120)}
	s := ParseSession(raw)
	if s.PlayersMax != 120 {
		t.Errorf("playersMax = %d, want 120 (settings takes precedence)", s.PlayersMax)
	}
}

func TestParseSession_fallsBackToSessionNameThenAddress(t *testing.T) {
	// Remove the human-typed name; SESSIONNAME_s should win.
	raw := sampleSession()
	attrs := raw["attributes"].(map[string]any)
	delete(attrs, "CUSTOMSERVERNAME_s")
	s := ParseSession(raw)
	if s.Name != "session-manufactured" {
		t.Errorf("name = %q, want session-manufactured", s.Name)
	}

	// Remove both; the address is the last-resort fallback.
	delete(attrs, "SESSIONNAME_s")
	s = ParseSession(raw)
	if s.Name != "5.62.114.92:7779" {
		t.Errorf("name = %q, want address fallback", s.Name)
	}
}

func TestParseSession_rejectsMissingOrBadAddress(t *testing.T) {
	cases := []struct {
		name  string
		mutate func(map[string]any)
	}{
		{"no address", func(m map[string]any) { delete(m["attributes"].(map[string]any), "ADDRESS_s") }},
		{"non-ip address", func(m map[string]any) { m["attributes"].(map[string]any)["ADDRESS_s"] = "not-an-ip" }},
		{"zero port", func(m map[string]any) { m["attributes"].(map[string]any)["GAMEPORT_l"] = float64(0) }},
		{"out-of-range port", func(m map[string]any) { m["attributes"].(map[string]any)["GAMEPORT_l"] = float64(70000) }},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			raw := sampleSession()
			tc.mutate(raw)
			if s := ParseSession(raw); s != nil {
				t.Errorf("expected nil for %s, got %+v", tc.name, s)
			}
		})
	}
}

func TestParseSession_readsNestedAttributesShape(t *testing.T) {
	// Some Epic regions send `attributes.attributes` — one level deeper.
	// The parser must unwrap that.
	raw := sampleSession()
	inner := raw["attributes"].(map[string]any)
	raw["attributes"] = map[string]any{"attributes": inner}
	s := ParseSession(raw)
	if s == nil || s.IP != "5.62.114.92" {
		t.Errorf("nested-attributes envelope was not unwrapped: %+v", s)
	}
}
