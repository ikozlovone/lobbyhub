// Package applog wires up slog for the binaries: parse a level, open a
// (rotating) log file, install a text handler that writes to stderr and
// the file both.
//
// The point of a package rather than inline setup in main is that two
// binaries (a2s-benchmark, chstats-backfill) share the same logging shape
// and both can call one Setup.
package applog

import (
	"io"
	"log/slog"
	"os"
	"strings"

	"gopkg.in/natefinch/lumberjack.v2"
)

// Config is what Setup takes. Empty File means "stderr only".
type Config struct {
	Level string // debug / info / warn / error; empty = info
	File  string // path; empty disables the file sink
}

// Setup installs a slog default logger. Safe to call once at start of
// main. Returns any file it opened so main can close it on shutdown.
//
// Rotation defaults chosen for a modest cron-driven workload: 5 MB per
// active file, 3 compressed backups, dropped after 30 days. On a
// ten-minute cron with 46 games that gives about a week of history per
// active file, and the total on disk never exceeds ~15 MB.
func Setup(cfg Config) io.Closer {
	handler := slog.NewTextHandler(sink(cfg.File), &slog.HandlerOptions{
		Level: parseLevel(cfg.Level),
	})
	slog.SetDefault(slog.New(handler))

	// Lumberjack's Logger implements io.Closer; main defers Close so
	// the last write flushes cleanly. When there's no file, return a
	// no-op closer so callers do not need to nil-check.
	if cfg.File == "" {
		return noopCloser{}
	}
	return openFile(cfg.File)
}

func sink(file string) io.Writer {
	if file == "" {
		return os.Stderr
	}
	return io.MultiWriter(os.Stderr, openFile(file))
}

// openFile is called twice by Setup — once for the writer, once so main
// gets a Closer. Lumberjack is safe to instantiate twice for the same
// path: it holds an internal mutex around writes.
func openFile(path string) *lumberjack.Logger {
	return &lumberjack.Logger{
		Filename:   path,
		MaxSize:    5,  // MB per active file before rotating
		MaxBackups: 3,  // compressed archives to keep
		MaxAge:     30, // days
		Compress:   true,
	}
}

func parseLevel(s string) slog.Level {
	switch strings.ToLower(strings.TrimSpace(s)) {
	case "debug":
		return slog.LevelDebug
	case "warn", "warning":
		return slog.LevelWarn
	case "error", "err":
		return slog.LevelError
	default:
		return slog.LevelInfo
	}
}

type noopCloser struct{}

func (noopCloser) Close() error { return nil }
