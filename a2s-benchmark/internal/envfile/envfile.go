// Package envfile reads a Laravel-style .env into the process environment
// and turns the DB_* vars into a Postgres DSN. Extracted from cmd/ so more
// than one binary can use the same rules — a2s-benchmark and
// chstats-backfill both need to talk to the same Postgres named the same
// way in the same file.
package envfile

import (
	"bufio"
	"fmt"
	"net/url"
	"os"
	"strings"
)

// Load reads a Laravel-style .env at path and copies each key into the
// process environment. Missing file is not an error — running without one
// is a legitimate mode. Existing env vars win: a value set by the shell
// must not be silently overwritten by a stale file on disk.
//
// Supported shape: `KEY=value`, `KEY="quoted with spaces"`, `KEY='raw'`,
// blank lines, and `#` comments. Multi-line values, variable expansion,
// and `export` prefixes are not supported — the Laravel `.env` this reads
// alongside does not use them either.
//
// Returns (loaded, err). loaded=false means the file did not exist.
func Load(path string) (bool, error) {
	f, err := os.Open(path)
	if err != nil {
		if os.IsNotExist(err) {
			return false, nil
		}
		return false, fmt.Errorf("open %s: %w", path, err)
	}
	defer f.Close()

	sc := bufio.NewScanner(f)
	line := 0
	for sc.Scan() {
		line++
		raw := strings.TrimSpace(sc.Text())
		if raw == "" || strings.HasPrefix(raw, "#") {
			continue
		}
		key, value, ok := parseLine(raw)
		if !ok {
			return true, fmt.Errorf("%s:%d: malformed line", path, line)
		}
		if _, alreadySet := os.LookupEnv(key); alreadySet {
			continue
		}
		if err := os.Setenv(key, value); err != nil {
			return true, fmt.Errorf("setenv %s: %w", key, err)
		}
	}
	if err := sc.Err(); err != nil {
		return true, fmt.Errorf("read %s: %w", path, err)
	}
	return true, nil
}

// parseLine splits `KEY=value` or `KEY="value"` or `KEY='value'`.
// Trailing whitespace on a bare value is dropped; a quoted value keeps
// its exact contents. Anything that does not look like an assignment is
// rejected so a typo does not silently disappear.
func parseLine(s string) (string, string, bool) {
	eq := strings.IndexByte(s, '=')
	if eq <= 0 {
		return "", "", false
	}
	key := strings.TrimSpace(s[:eq])
	if key == "" {
		return "", "", false
	}
	value := s[eq+1:]

	// Strip an inline # comment on bare values (not on quoted ones).
	if len(value) == 0 || (value[0] != '"' && value[0] != '\'') {
		if hash := strings.IndexByte(value, '#'); hash >= 0 {
			value = value[:hash]
		}
		return key, strings.TrimSpace(value), true
	}

	// Quoted: everything up to the matching close quote is the value.
	quote := value[0]
	value = value[1:]
	end := strings.IndexByte(value, quote)
	if end < 0 {
		return "", "", false
	}
	return key, value[:end], true
}

// BuildDSN assembles a Postgres DSN from Laravel-style DB_* vars.
//
// url.URL.String() handles the percent-encoding rules for userinfo —
// specifically the `@`, `#`, `?`, `/`, `%` characters that Laravel `.env`
// accepts verbatim in a password but the DSN parser reads as URL syntax.
//
// sslmode defaults to disable — the tool talks to a local pgbouncer or a
// private-network Postgres by design, and requiring TLS on a loopback
// socket is what a production .env would already override.
//
// Returns "" when DB_HOST or DB_DATABASE is missing, so main can fall back
// to A2S_BENCHMARK_DSN.
func BuildDSN() string {
	host := os.Getenv("DB_HOST")
	db := os.Getenv("DB_DATABASE")
	if host == "" || db == "" {
		return ""
	}

	user := os.Getenv("DB_USERNAME")
	pass := os.Getenv("DB_PASSWORD")
	port := os.Getenv("DB_PORT")
	if port == "" {
		port = "5432"
	}
	sslmode := os.Getenv("DB_SSLMODE")
	if sslmode == "" {
		sslmode = "disable"
	}

	u := &url.URL{
		Scheme:   "postgres",
		Host:     host + ":" + port,
		Path:     "/" + db,
		RawQuery: "sslmode=" + sslmode,
	}
	if user != "" {
		if pass != "" {
			u.User = url.UserPassword(user, pass)
		} else {
			u.User = url.User(user)
		}
	}
	return u.String()
}
