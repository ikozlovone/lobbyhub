-- Player counts per game, as Steam reports them.
--
-- The other half of what this box records. `server_players_raw` answers "how
-- many people are on this server"; these answer "how many people are in this
-- game at all, anywhere on Steam" — a different number from a different source
-- (ISteamChartsService and GetNumberOfCurrentPlayers, not the master server),
-- and one no server list can produce. A game can be second on Steam by players
-- and have no dedicated servers at all; Dota 2 is.
--
-- Created by `steamstats` on every run (CREATE TABLE IF NOT EXISTS), so this
-- file is the readable copy rather than the authority. Apply by hand with:
--
--   clickhouse-client --database lobbyhub_stats --queries-file game_players.sql

CREATE TABLE IF NOT EXISTS game_players_raw
(
    -- Truncated to the ten-minute mark in UTC, the same bucket rule the server
    -- sweep uses, so a tick from either writer lines up with the other's.
    ts          DateTime,

    -- Steam's appid is the key, not our `games.id`: it is the only id both
    -- sides of this agree on, it is what the collector asks Steam for, and it
    -- lets a game be recorded before this catalog carries it — which is how
    -- the chart tells us what we are missing.
    app_id      UInt32,

    -- Our own id when we carry the game, 0 when we do not. Denormalised so a
    -- read for one of our games needs no join, and so a sweep of "games in the
    -- chart nobody here lists" is one WHERE clause.
    game_id     UInt32,

    players     UInt32,

    -- Position in Steam's official top 100 at the time of the tick, 0 for a
    -- game outside it. Also the provenance mark: a row with a rank came from
    -- the chart, one without came from a per-appid lookup.
    rank        UInt16,

    -- Steam's own 24h peak for the game, as published beside the chart. 0 when
    -- the tick came from a per-appid lookup, which does not carry one.
    peak_today  UInt32
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(ts)
ORDER BY (app_id, ts)
-- Six months, where the server table keeps seven days. It can afford to: a
-- tick is a few hundred rows rather than three hundred thousand, so a year of
-- this is smaller than a day of that.
TTL ts + INTERVAL 180 DAY;

CREATE TABLE IF NOT EXISTS game_players_daily
(
    date         Date,
    app_id       UInt32,
    game_id      UInt32,
    players_avg  Float32,
    players_max  UInt32,
    players_min  UInt32,
    samples      UInt32,
    -- Best (lowest) rank the game held that day; 0 when it was never charted.
    best_rank    UInt16
)
-- Replacing, keyed on the day: `steamstats --rollup` for a date that already
-- has rows replaces them rather than doubling them, so a re-run after a failed
-- night is safe. Reads that must not see both copies use FINAL.
ENGINE = ReplacingMergeTree
ORDER BY (app_id, date);
