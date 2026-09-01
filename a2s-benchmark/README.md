# a2s-benchmark

Standalone Go CLI that sweeps every server of one LobbyHub game and
reports how long it took. Read-only by default; the optional `--write`
flag turns the sweep into a collector that pushes each result to
`server_states` as a batched UPDATE.

Two protocols are supported today. The runner picks per game from
`games.query_protocol`:

- **`source`** — Valve A2S over UDP. Rust, ARK, CS2, Garry's Mod,
  7 Days to Die, TF2, DayZ, Squad, and any other Source-based server.
- **`minecraft`** — Server List Ping (Java Edition, 1.7+) over TCP.

FiveM (`fivem`) and any other protocol is loaded but skipped — the
summary reports it under "unknown protocol" so an operator sees why
zero servers were probed.

Purpose is measurement, not monitoring — the numbers here decide whether
LobbyHub eventually replaces its per-server PHP job with a Go collector.
See `../a2s-benchmark.txt` for the specification.

## Build

```
cd a2s-benchmark
go build -o a2s-benchmark.bin ./cmd/a2s-benchmark
go build -o chstats-backfill.bin ./cmd/chstats-backfill
go build -o steamstats.bin ./cmd/steamstats
```

The `.bin` suffix keeps the binary distinguishable from the source
directory of the same name — running the binary from the parent directory
would otherwise clash with the `a2s-benchmark/` folder.

## Run

```
ulimit -n 65536
cd /var/www/lobbyhub/a2s-benchmark

# whole catalog, production write mode, optimal values
./a2s-benchmark.bin --all-games --concurrency=3000 --timeout=500ms --write

# one game
./a2s-benchmark.bin --game=counter-strike-2 --concurrency=3000 --timeout=500ms --write
```

Run `./a2s-benchmark.bin --help` for the full list of flags and more examples.

## Configuration

Postgres credentials are resolved in this order (first non-empty wins):

1. `--dsn=<url>` — an explicit DSN on the command line.
2. `$A2S_BENCHMARK_DSN` — one env var carrying the whole URL.
3. Laravel-style `DB_*` variables from `.env`:
   - `DB_HOST`, `DB_PORT` (default `5432`), `DB_DATABASE`
   - `DB_USERNAME`, `DB_PASSWORD` (URL-encoded automatically — no need to
     hand-escape `#`, `@`, `%`, …)
   - `DB_SSLMODE` (default `disable`)

The `.env` path defaults to `./.env`; override with `--env=/path/to/.env`.
Missing file is silently skipped. Values already in the process
environment win over anything from the file, so a shell export overrides
the file without needing to edit it.

The same `.env` your Laravel app uses works out of the box — the DB_*
names match. Just make sure the DSN user has `UPDATE` on
`server_states` if you plan to pass `--write`.

## Write mode

`--write` turns the sweep into a collector. Each result — online or
offline — is queued and flushed as one `UPDATE server_states ... FROM
(VALUES ...)` per batch of 500 rows, one background goroutine.

Columns written per outcome:

| Outcome  | status  | players_online | last_queried_at | last_online_at | last_offline_at | failed_queries_count |
|----------|---------|----------------|------------------|-----------------|------------------|----------------------|
| responded | `online`  | from A2S | now | now | (kept) | `0` |
| timeout / network / other | `offline` | `0` | now | (kept) | now | `LEAST(prev+1, 65535)` |

On a responded result these Info fields are also written when present:
`players_max`, `bots`, `vac_enabled`, `map`, `reported_version`, `motd`
(from A2S `Name`), `game_port`, `steam_id`. Empty/zero values leave the
old column untouched via `COALESCE` — a driver that does not report a
field never blanks it.

Not touched by `--write`: `next_query_at`, `steam_seen_at`, `wiped_at`,
`players_queued`, `uptime_percent`, `servers.name` adoption, rank
recompute, `server_stats` history. Those stay on the PHP side.

### Games counters (also written)

At the end of each sweep, one UPDATE on the game row keeps the
denormalized counters current:

- `games.online_servers_count` — count of responded outcomes this sweep
- `games.players_online`       — sum of `players_online` across those
- `games.stats_synced_at`      — `NOW() AT TIME ZONE 'UTC'`

Free numbers — aggregated from RAM as results come in, no extra scan of
`server_states`. **Not touched:** `games.servers_count` (that column
also counts offline servers we may have skipped this round, so it stays
with `CatalogCounters::refresh`) and `games.facets` (that stays with
`facets:refresh`).

After the UPDATE, one Redis `DEL` drops Laravel's `api:games` cache
entry so the homepage nav rail shows the fresh counters within seconds
instead of the 10-minute API TTL. Reads `REDIS_HOST`, `REDIS_PORT`,
`REDIS_PASSWORD`, `REDIS_CACHE_DB` (default 1) and composes the key
using `REDIS_PREFIX` + `CACHE_PREFIX` + `api:games` — Laravel's own
key formation. Empty `REDIS_HOST` means the environment isn't using
Redis and the DEL is a no-op. Failure is logged and skipped; the sweep
does not depend on Redis.

### ClickHouse stats (optional)

When `--write` is on and `CH_HOST` is set in the environment, every
**responded** server (only online, not timeouts) is also queued for a
batched INSERT into `lobbyhub_stats.server_players_raw`. One row per
sweep per online server:

```
ts             = time.Now().UTC().Truncate(10 * time.Minute)
game_id        = games.id
server_id      = servers.id
players_online = from A2S / SLP response
```

The `ts` is the same for every row in a game's sweep — all rows drop
into one 10-minute bucket regardless of how long the sweep took.

Environment:

- `CH_HOST` — required to enable stats writes (empty = no-op)
- `CH_PORT` — default `9000`
- `CH_DATABASE` — default `lobbyhub_stats`
- `CH_USERNAME` — default `default`
- `CH_PASSWORD` — plaintext

Raw table has a 7-day TTL; a daily rollup to `server_players_daily`
runs from cron outside the tool (see `/usr/local/bin/lobbyhub-ch-rollup.sh`).
Fail-open: a rejected INSERT is counted in the summary and the sweep
keeps running.

Fail-open: a batch the DB rejects is counted (`batch errors` in the
summary) and dropped — the sweep keeps running. The channel between
sweep and writer holds ten batches; past that Enqueue blocks and the
sweep slows down to match the writer.

The DSN user needs `UPDATE ON server_states, games` in addition to
`SELECT ON servers, games` — `lobbyhub_user` has both.

## steamstats — players per game

A second binary in this module, and a different question. The sweep asks
every server how many players are on it; `steamstats` asks Valve how many
people are in a game *anywhere on Steam*, which is a number no server list
can produce and which does not come from the master server at all:

- `ISteamChartsService/GetGamesByConcurrentPlayers` — the official top 100
  in one request: rank, appid, players now, peak today. This is the same
  ranking every "Steam charts" page republishes.
- `ISteamUserStats/GetNumberOfCurrentPlayers` — one appid, one request, no
  rank and no peak. What the games below the top 100 cost.

Neither needs an API key. Neither counts servers: a game can be second on
Steam by players and have no dedicated servers at all (Dota 2 is).

One run is one tick — read the games with a `steam_appid` from Postgres,
fetch the chart, look up whatever the chart did not cover, write it all as
one batch stamped with the tick's own ten-minute mark, the same bucket rule
the server sweep uses. Charted games this catalog does not carry are
recorded too, with `game_id = 0`: they cost nothing and they answer "which
games with a live playerbase are we missing".

```
./steamstats.bin --env=/var/www/lobbyhub/.env            # a tick
./steamstats.bin --env=/var/www/lobbyhub/.env --dry-run  # collect, write nothing
./steamstats.bin --env=/var/www/lobbyhub/.env --rollup   # yesterday into daily
```

Cron, every ten minutes plus one rollup after midnight UTC:

```
*/10 * * * * /usr/local/bin/steamstats --env=/var/www/lobbyhub/.env --log-file=/var/log/lobbyhub/steamstats.log
20 0 * * *   /usr/local/bin/steamstats --env=/var/www/lobbyhub/.env --rollup
```

Two tables, created on every run with `IF NOT EXISTS`; `schema/game_players.sql`
is the readable copy:

```
game_players_raw    ts, app_id, game_id, players, rank, peak_today   180-day TTL
game_players_daily  date, app_id, game_id, avg/max/min, samples, best_rank
```

`app_id` is the key rather than our `games.id` — it is the only id both
sides agree on, and it lets a game be recorded before the catalog carries
it. `game_id` rides along denormalised so a read for one of our games needs
no join. The daily table is a `ReplacingMergeTree` keyed on
`(app_id, date)`, so re-running a rollup supersedes the day rather than
doubling it.

Unlike the sweep, ClickHouse is not optional here: it is the only thing this
writes, so a missing `CH_HOST` is an error rather than a quiet no-op. By
default only games the site is showing are polled; `--all-games` includes
the ones switched off, which is what a week of history for a catalog import
under review wants.

## Output

- **stdout** — live progress line every second (processed / online /
  timeout / errors, in-flight, servers/sec, p50/p95/p99), plus the final
  summary with wall clock time, response rate, latency distribution,
  peak memory and goroutines, database write stats when `--write` is set,
  and the config used. Meant for terminal viewing.
- **structured log (slog)** — warnings and errors, plus one
  `sweep start` / `sweep done` INFO event per game with the key metrics as
  attributes. Written to stderr, and to a rotating file when `--log-file`
  is set.

## Logging

The tool speaks `log/slog` text format for structured events; the pretty
summary and progress ticker stay on stdout.

- `--log-level=info|warn|error|debug` — default `info`.
- `--log-file=<path>` — also write the log to this file. Rotates on
  size (5 MB per active file, 3 compressed backups, 30 days), so total
  disk footprint stays under ~15 MB per install.

Format is one line per event, key=value attributes:

```
time=2026-08-26T12:34:56.789Z level=INFO msg="sweep done" game=counter-strike-2 elapsed_ms=27100 responded=44730 players_online=312450 timeouts=125943 net_errors=6638 db_written=44730 db_errors=0 db_retries=0 ch_written=44730 ch_errors=0
```

Machine-readable — pipe into `grep`, `awk`, or a log-shipper for
alerting. Without `--log-file`, everything still shows up on stderr.

## Layout

```
cmd/a2s-benchmark      the CLI
cmd/steamstats         the per-game player-count collector
internal/snapshot      transport-agnostic Outcome + Info the writer takes
internal/a2s           Valve A2S over UDP (Source engine)
internal/slp           Minecraft Server List Ping over TCP (1.7+)
internal/steam         Valve's charts and player-count endpoints
internal/repository    Postgres access — LoadForGame + batching writer
internal/benchmark     runner, bounded queue, rate limiter, stats
internal/metrics       latency histogram
```

The protocol packages depend on `snapshot` and nothing else — they can be
lifted into a production collector without dragging the runner or the
Postgres side along.

## Minecraft SRV caveat

The SLP client dials `ip_address:port` directly and does not resolve
`_minecraft._tcp.<host>` SRV records. Servers that advertise a non-default
port via SRV and whose stored `port` is the SRV default will hit the
wrong socket and time out. The PHP `MinecraftQueryDriver` does resolve
SRV; that logic can be lifted here later if needed.

## Sample plan

Same game, five configs (per §19 of the spec):

```
--concurrency=100  --timeout=1s --retries=0
--concurrency=250  --timeout=1s --retries=0
--concurrency=500  --timeout=1s --retries=0
--concurrency=1000 --timeout=1s --retries=0
--concurrency=2000 --timeout=1s --retries=0
```

Stop climbing if you see packet loss, socket exhaustion, or a jump in
timeout rate.
