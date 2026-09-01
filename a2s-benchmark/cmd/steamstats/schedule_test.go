package main

import (
	"testing"
	"time"
)

// The two decisions the service loop makes between ticks. Both fail quietly
// when they are wrong — a drifting tick shows up weeks later as a gap in a
// chart, and a rollup that never fires shows up as an empty daily table — so
// they are pulled out of the loop and pinned here.

func TestNextTickLandsOnTheClock(t *testing.T) {
	const interval = 10 * time.Minute

	cases := []struct {
		now  string
		want string
	}{
		// The point of aligning: a service started at an awkward moment still
		// ticks on the boundaries the rows are stamped with.
		{"2026-09-01T09:03:17Z", "2026-09-01T09:10:00Z"},
		{"2026-09-01T09:09:59Z", "2026-09-01T09:10:00Z"},
		// Exactly on one is due for the next, not for itself again.
		{"2026-09-01T09:10:00Z", "2026-09-01T09:20:00Z"},
		{"2026-09-01T23:57:04Z", "2026-09-02T00:00:00Z"},
	}

	for _, c := range cases {
		now := parse(t, c.now)
		got := nextTick(now, interval)

		if want := parse(t, c.want); !got.Equal(want) {
			t.Errorf("nextTick(%s) = %s, want %s", c.now, got.UTC().Format(time.RFC3339), c.want)
		}
	}
}

func TestRollupDue(t *testing.T) {
	cases := []struct {
		name   string
		now    string
		rolled string
		want   string // empty means "not due"
	}{
		{"first tick of a fresh process", "2026-09-01T09:03:00Z", "", "2026-08-31"},
		{"already done today", "2026-09-01T09:03:00Z", "2026-08-31", ""},
		// The last ticks of the day just ended may still be landing.
		{"too early in the new day", "2026-09-01T00:05:00Z", "", ""},
		{"late enough in the new day", "2026-09-01T00:20:00Z", "", "2026-08-31"},
		// An hourly interval never sees minute 15 of hour 0; the rule is
		// "into the day", not "in the first hour".
		{"hourly interval, first tick after midnight", "2026-09-01T01:00:00Z", "", "2026-08-31"},
		// The day turns over and yesterday becomes a different date.
		{"the day after", "2026-09-02T09:00:00Z", "2026-08-31", "2026-09-01"},
	}

	for _, c := range cases {
		day, due := rollupDue(parse(t, c.now), c.rolled)

		if c.want == "" {
			if due {
				t.Errorf("%s: due for %s, want not due", c.name, day.Format(dayFormat))
			}
			continue
		}
		if !due {
			t.Errorf("%s: not due, want %s", c.name, c.want)
			continue
		}
		if got := day.Format(dayFormat); got != c.want {
			t.Errorf("%s: due for %s, want %s", c.name, got, c.want)
		}
	}
}

func parse(t *testing.T, stamp string) time.Time {
	t.Helper()

	at, err := time.Parse(time.RFC3339, stamp)
	if err != nil {
		t.Fatalf("bad timestamp %q: %v", stamp, err)
	}
	return at
}
