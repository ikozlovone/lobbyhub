package main

import (
	"context"
	"log/slog"
	"os"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/redis/go-redis/v9"
)

// forgetGamesCache mirrors what Laravel's CatalogCounters::refresh does at
// the end: `Cache::forget('api:games')`. The Redis-backed cache store
// stores that under `{redis_prefix}{cache_prefix}api:games`, which is
// what we DEL directly.
//
// Best-effort — if REDIS_HOST is not set, or the DEL fails, we log and
// move on. The sweep succeeded, the counter is in the DB, the UI will
// catch up on its own within the API cache TTL (10 min).
func forgetGamesCache(ctx context.Context) {
	client := newRedisClient()
	if client == nil {
		return
	}
	defer client.Close()

	key := laravelCacheKey("api:games")

	subCtx, cancel := context.WithTimeout(ctx, 2*time.Second)
	defer cancel()

	if err := client.Del(subCtx, key).Err(); err != nil {
		slog.Warn("redis DEL failed", "key", key, "err", err)
	}
}

// newRedisClient reads the Laravel-style REDIS_* vars and returns a client
// pointed at the cache database. Returns nil when REDIS_HOST is absent,
// which is the signal that this environment is not using Redis and the
// invalidation is a no-op.
func newRedisClient() *redis.Client {
	host := os.Getenv("REDIS_HOST")
	if host == "" {
		return nil
	}
	port := os.Getenv("REDIS_PORT")
	if port == "" {
		port = "6379"
	}

	// Laravel routes cache reads to REDIS_CACHE_DB (default 1) — see the
	// `cache` connection in config/database.php. Using the default DB (0)
	// would DEL a key that Laravel never wrote there.
	db := 1
	if s := os.Getenv("REDIS_CACHE_DB"); s != "" {
		if n, err := strconv.Atoi(s); err == nil {
			db = n
		}
	}

	return redis.NewClient(&redis.Options{
		Addr:     host + ":" + port,
		Password: os.Getenv("REDIS_PASSWORD"),
		DB:       db,
		// Every call also has its own ctx-timeout, but a very slow socket
		// dial should still fail fast rather than hold up the sweep.
		DialTimeout:  2 * time.Second,
		ReadTimeout:  2 * time.Second,
		WriteTimeout: 2 * time.Second,
	})
}

// laravelCacheKey composes the exact key Laravel would write for a
// Cache::forget call, respecting REDIS_PREFIX and CACHE_PREFIX from the
// same .env. Both prefixes default to `<slug(APP_NAME)>-database-` and
// `<slug(APP_NAME)>-cache-` respectively (see config/database.php and
// config/cache.php); an operator overriding APP_NAME or the prefixes in
// their .env must have set the same values we read here.
func laravelCacheKey(key string) string {
	appSlug := slugify(getenvOr("APP_NAME", "laravel"))

	redisPrefix := getenvOr("REDIS_PREFIX", appSlug+"-database-")
	cachePrefix := getenvOr("CACHE_PREFIX", appSlug+"-cache-")

	return redisPrefix + cachePrefix + key
}

func getenvOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

// slugify approximates Laravel's Str::slug for the common case of an
// ASCII APP_NAME. Non-ASCII names risk drifting from Laravel's own
// transliteration — the escape hatch is to set REDIS_PREFIX and
// CACHE_PREFIX explicitly in .env, which both this tool and Laravel
// then take at face value.
var slugSeparator = regexp.MustCompile(`[^a-z0-9]+`)

func slugify(s string) string {
	s = strings.ToLower(s)
	s = slugSeparator.ReplaceAllString(s, "-")
	return strings.Trim(s, "-")
}
