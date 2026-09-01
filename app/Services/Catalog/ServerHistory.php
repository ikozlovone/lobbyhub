<?php

namespace App\Services\Catalog;

use App\Models\Server;
use App\Services\Stats\ClickHouseClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Feeds the player-count chart on the server detail page.
 *
 * Short ranges (24h, 7d) come from raw 10-minute samples in
 * `lobbyhub_stats.server_players_raw`; long ranges (30d, 1y) come from
 * daily rollups in `lobbyhub_stats.server_players_daily`. Both live in
 * ClickHouse — the two Postgres tables this class used to read
 * (`server_stats`, `server_daily_stats`) are being retired.
 *
 * Raw samples are still downsampled in PHP rather than in SQL: the row
 * count is bounded (a week of ten-minute points is under 1,100 rows per
 * server) and keeping the SQL simple means the reader is not tied to a
 * ClickHouse-specific bucketing function.
 *
 * `is_online` is not stored on the CH side — the sweeper writes only
 * responded servers, so a row's existence is itself the online mark, and
 * gaps in the timeline are the offline periods. The 'online' flag in the
 * downsampled output stays `true` for every bucket; the frontend already
 * shows gaps as breaks in the chart line, which is what we want.
 *
 * Fail-open: if ClickHouse is unreachable or misconfigured, the reader
 * logs a warning and returns an empty point list. The chart shows "no
 * history yet" instead of taking the whole page down with it — matching
 * the behaviour a freshly-added server already has.
 */
class ServerHistory
{
    /** Ranges the API accepts, in days, and where each reads from. */
    private const RANGES = [
        '24h' => ['days' => 1, 'source' => 'raw'],
        '7d' => ['days' => 7, 'source' => 'raw'],
        '30d' => ['days' => 30, 'source' => 'daily'],
        '1y' => ['days' => 365, 'source' => 'daily'],
    ];

    /** Charts do not benefit from more points than a chart is wide. */
    private const MAX_POINTS = 240;

    /**
     * A day at the sweeper's 10-minute cadence has 144 potential samples.
     * `samples_count / 144` is the closest we can get to an uptime figure
     * out of the daily rollup as it stands today — the number ClickHouse
     * knows is "how many times we saw this server online" and the frontend
     * shows a percentage. When the writer starts emitting a total_samples
     * column too, this constant goes away in favour of a real ratio.
     */
    private const EXPECTED_SAMPLES_PER_DAY = 144;

    public function __construct(private readonly ClickHouseClient $ch) {}

    public function for(Server $server, string $range): array
    {
        $config = self::RANGES[$range] ?? self::RANGES['24h'];
        $since = now()->subDays($config['days']);

        return [
            'range' => $range,
            'source' => $config['source'],
            'points' => $config['source'] === 'raw'
                ? $this->fromRaw($server, $since)
                : $this->fromDaily($server, $since),
        ];
    }

    private function fromRaw(Server $server, Carbon $since): array
    {
        try {
            $rows = $this->ch->query(
                'SELECT ts, players_online
                   FROM server_players_raw
                  WHERE server_id = {sid:UInt64}
                    AND ts >= {since:DateTime}
                  ORDER BY ts',
                [
                    'sid' => (string) $server->id,
                    'since' => $since->utc()->format('Y-m-d H:i:s'),
                ],
            );
        } catch (RuntimeException $e) {
            Log::warning('ServerHistory raw query failed', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return $this->downsample(collect($rows), function (Collection $bucket) {
            $first = $bucket->first();

            return [
                // CH sends timestamps in ClickHouse's own DateTime format —
                // 'YYYY-MM-DD HH:MM:SS' in UTC. Parse explicitly rather than
                // trusting the server's default timezone.
                'at' => Carbon::parse($first['ts'], 'UTC')->toIso8601String(),
                'players' => (int) round($bucket->avg('players_online')),
                'online' => true,
            ];
        });
    }

    private function fromDaily(Server $server, Carbon $since): array
    {
        try {
            $rows = $this->ch->query(
                'SELECT date, players_avg, players_max, samples_count
                   FROM server_players_daily
                  WHERE server_id = {sid:UInt64}
                    AND date >= {since:Date}
                  ORDER BY date',
                [
                    'sid' => (string) $server->id,
                    'since' => $since->format('Y-m-d'),
                ],
            );
        } catch (RuntimeException $e) {
            Log::warning('ServerHistory daily query failed', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return array_map(fn (array $row) => [
            'at' => $row['date'],
            'players' => (int) round((float) $row['players_avg']),
            'peak' => (int) $row['players_max'],
            // See EXPECTED_SAMPLES_PER_DAY — approximate, capped at 100 so a
            // day with a burst of catch-up writes cannot report >100%.
            'uptime' => min(100.0, round(
                100.0 * (int) $row['samples_count'] / self::EXPECTED_SAMPLES_PER_DAY,
                2,
            )),
        ], $rows);
    }

    /**
     * Average consecutive samples into at most MAX_POINTS buckets. A gap in
     * the samples stays a gap: buckets are built by position, not by clock
     * time, so an offline period shows up as a smaller number of buckets
     * (fewer rows returned by ClickHouse) rather than being smoothed away.
     */
    private function downsample(Collection $samples, callable $reduce): array
    {
        if ($samples->isEmpty()) {
            return [];
        }

        $size = (int) max(1, ceil($samples->count() / self::MAX_POINTS));

        return $samples->chunk($size)->map($reduce)->values()->all();
    }

    /** @return list<string> */
    public static function ranges(): array
    {
        return array_keys(self::RANGES);
    }

    /**
     * The ranges a fresh sample can change.
     *
     * A manual refresh writes one point into the current ten-minute bucket, so
     * the two raw-backed ranges are a point out of date the moment it lands.
     * The long ones are built from the daily rollup, which a cron writes once a
     * day and this cannot touch — dropping their cached copies would re-run the
     * heaviest read in the API for an identical answer.
     *
     * @return list<string>
     */
    public static function liveRanges(): array
    {
        return array_keys(array_filter(
            self::RANGES,
            fn (array $config) => $config['source'] === 'raw',
        ));
    }

    /** Where one range's answer is kept; used to store it and to drop it. */
    public static function cacheKey(Server $server, string $range): string
    {
        return "api:servers:{$server->id}:history:{$range}";
    }
}
