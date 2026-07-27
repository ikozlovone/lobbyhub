<?php

namespace App\Services\Catalog;

use App\Models\Server;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Feeds the player-count chart.
 *
 * Short ranges come from raw samples, long ranges from the daily rollups —
 * the same split the storage layer already uses. Raw samples are downsampled
 * in PHP rather than in SQL: time bucketing is dialect-specific, the row count
 * per server is bounded, and this keeps the query portable.
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

    public function for(Server $server, string $range): array
    {
        $config = self::RANGES[$range] ?? self::RANGES['24h'];
        $since = now()->subDays($config['days']);

        return [
            'range' => $range,
            'source' => $config['source'],
            'points' => $config['source'] === 'raw'
                ? $this->fromSamples($server, $since)
                : $this->fromDailyStats($server, $since),
        ];
    }

    private function fromSamples(Server $server, Carbon $since): array
    {
        $samples = $server->stats()
            ->where('recorded_at', '>=', $since)
            ->orderBy('recorded_at')
            ->get(['recorded_at', 'is_online', 'players_online']);

        return $this->downsample($samples, fn (Collection $bucket) => [
            'at' => $bucket->first()->recorded_at->toIso8601String(),
            'players' => (int) round($bucket->avg('players_online')),
            'online' => $bucket->contains(fn ($row) => $row->is_online),
        ]);
    }

    private function fromDailyStats(Server $server, Carbon $since): array
    {
        return $server->dailyStats()
            ->where('date', '>=', $since->toDateString())
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'at' => $row->date->toDateString(),
                'players' => (int) round((float) $row->players_avg),
                'peak' => $row->players_peak,
                'uptime' => (float) $row->uptime_percent,
            ])
            ->all();
    }

    /**
     * Average consecutive samples into at most MAX_POINTS buckets. A gap in the
     * samples stays a gap: buckets are built by position, not by clock time, so
     * downtime shows up as `online: false` rather than being smoothed away.
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
