<?php

namespace App\Services\Catalog;

use App\Models\Game;
use App\Services\Stats\ClickHouseClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * How many people were playing a game, over time.
 *
 * The sibling of ServerHistory and a different number: that one is players on
 * one server we monitor, this one is everybody in the game anywhere on Steam.
 * Both are ten-minute samples in ClickHouse, written by different collectors —
 * `steamstats` fills these tables, and it is the only thing that does.
 *
 * Keyed on the Steam appid rather than our own game id, because that is what
 * the collector writes: it records charted games this catalog does not carry
 * yet, and those have no id of ours to be keyed by.
 *
 * Fail-open in the same way, and for the same reason: an unreachable analytics
 * box must cost a chart, not a page.
 */
class GameHistory
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

    public function __construct(private readonly ClickHouseClient $ch) {}

    /**
     * @return array{range: string, source: string, recording_since: string|null, points: array<int, array<string, mixed>>}
     */
    public function for(Game $game, string $range): array
    {
        $config = self::RANGES[$range] ?? self::RANGES['24h'];
        $appId = (int) $game->steam_appid;

        if ($appId < 1) {
            return ['range' => $range, 'source' => $config['source'], 'recording_since' => null, 'points' => []];
        }

        $since = now()->subDays($config['days']);

        return [
            'range' => $range,
            'source' => $config['source'],
            /*
             * When this game's history starts.
             *
             * Not decoration: these tables begin the day the collector is
             * switched on, so a chart covering a year is drawing a fortnight
             * and saying nothing about it. The page prints the date instead of
             * letting an empty left half read as a game nobody played.
             */
            'recording_since' => $this->recordingSince($appId),
            'points' => $config['source'] === 'raw'
                ? $this->fromRaw($appId, $since)
                : $this->fromDaily($appId, $since),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function fromRaw(int $appId, Carbon $since): array
    {
        $rows = $this->read(
            /*
             * `toString(ts, 'UTC')` and not `ts`.
             *
             * A bare `DateTime` column has no timezone of its own, so
             * ClickHouse renders it in whatever the server is set to — on a
             * Moscow box that is three hours ahead of the instant recorded,
             * and this reader then labelled the string UTC and the browser
             * added another three. Asking for the timezone by name makes the
             * answer the same on every machine.
             *
             * The bound is wrapped for the same reason: a `{x:DateTime}`
             * parameter is parsed in the server's timezone too, so a UTC
             * string went in three hours out.
             */
            'SELECT toString(ts, \'UTC\') AS ts, players
               FROM game_players_raw
              WHERE app_id = {app:UInt32}
                AND ts >= toDateTime({since:String}, \'UTC\')
              ORDER BY ts',
            ['app' => $appId, 'since' => $since->utc()->format('Y-m-d H:i:s')],
        );

        return $this->downsample(collect($rows), function (Collection $bucket) {
            $first = $bucket->first();

            return [
                // ClickHouse hands timestamps back in its own format, in UTC.
                // Parsed explicitly rather than against the server's default.
                'at' => Carbon::parse($first['ts'], 'UTC')->toIso8601String(),
                'players' => (int) round($bucket->avg('players')),
            ];
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function fromDaily(int $appId, Carbon $since): array
    {
        $rows = $this->read(
            'SELECT date, players_avg, players_max
               FROM game_players_daily
              WHERE app_id = {app:UInt32}
                AND date >= {since:Date}
              ORDER BY date',
            ['app' => $appId, 'since' => $since->format('Y-m-d')],
        );

        return array_map(fn (array $row) => [
            'at' => $row['date'],
            'players' => (int) round((float) $row['players_avg']),
            'peak' => (int) $row['players_max'],
        ], $rows);
    }

    /**
     * The first sample there is for this game.
     *
     * `min(ts)` over a table sorted by `(app_id, ts)` is a primary-key read of
     * one mark, not a scan.
     */
    private function recordingSince(int $appId): ?string
    {
        $rows = $this->read(
            'SELECT toString(min(ts), \'UTC\') AS first FROM game_players_raw WHERE app_id = {app:UInt32}',
            ['app' => $appId],
        );

        $first = $rows[0]['first'] ?? null;

        // ClickHouse answers an empty table with the epoch rather than null.
        if ($first === null || str_starts_with((string) $first, '1970')) {
            return null;
        }

        return Carbon::parse($first, 'UTC')->toIso8601String();
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<int, array<string, mixed>>
     */
    private function read(string $sql, array $params): array
    {
        try {
            return $this->ch->query($sql, $params);
        } catch (RuntimeException $e) {
            Log::warning('GameHistory query failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * One point per bucket, so a week of ten-minute samples arrives as a chart
     * rather than as a thousand rows the browser has to thin itself.
     *
     * @param  Collection<int, array<string, mixed>>  $samples
     * @return array<int, array<string, mixed>>
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
}
