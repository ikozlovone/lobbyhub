<?php

namespace App\Services\Catalog;

use App\Models\Game;
use App\Services\Stats\ClickHouseClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The aggregates behind the tables: hours played, and month against month.
 *
 * Neither number exists at Valve. Their charts service publishes a rank, a
 * concurrent count, a 24-hour peak and last week's rank, and that is the whole
 * of it — no playtime anywhere. Every "hours played" column on every Steam
 * charts site is the same arithmetic on the same kind of samples: a reading of
 * N concurrent players standing for the interval between readings is N × that
 * interval in player-hours. Ours are ten minutes apart, so each one is a sixth
 * of an hour per player.
 *
 * That makes the figure honest about what it is — hours we observed, not hours
 * Steam reported — and it is why a game the collector reached an hour ago shows
 * an hour's worth rather than a day's. The copy on the page says so.
 *
 * Fail-open like every other reader here: an unreachable ClickHouse costs a
 * table, not a page.
 */
class GameTrends
{
    /**
     * Minutes one sample stands for — the collector's tick, and the width of
     * the bucket its timestamps are truncated to. If that interval ever
     * changes, this is the number that has to change with it.
     */
    private const SAMPLE_MINUTES = 10;

    public function __construct(private readonly ClickHouseClient $ch) {}

    /**
     * Player-hours over the last day, for every game at once.
     *
     * One query for the whole chart rather than one per row: the index needs
     * forty of these and they come out of a single scan of one partition.
     *
     * @return array<int, float> keyed by Steam appid
     */
    public function hoursByApp(int $hours = 24): array
    {
        $rows = $this->read(
            'SELECT app_id, sum(players) * {minutes:UInt16} / 60 AS hours
               FROM game_players_raw
              WHERE ts >= now() - INTERVAL {hours:UInt16} HOUR
              GROUP BY app_id',
            ['minutes' => self::SAMPLE_MINUTES, 'hours' => $hours],
        );

        $byApp = [];

        foreach ($rows as $row) {
            $byApp[(int) $row['app_id']] = (float) $row['hours'];
        }

        return $byApp;
    }

    /**
     * One game, month by month, newest first, with a "last 30 days" row on top.
     *
     * The shape steamcharts.com made the convention for this kind of page, and
     * it is the right one: an average says what a month was like, a peak says
     * what it managed, and the gain between two months is the only one of the
     * three that answers "is this game growing".
     *
     * Read from the daily rollup rather than the raw samples — a year of months
     * is twelve rows out of a table with one row per game per day. `FINAL`
     * because that table is a ReplacingMergeTree and a re-run rollup leaves two
     * copies of a day until the parts merge; counting both would inflate the
     * month.
     *
     * @return array{months: array<int, array<string, mixed>>, recording_since: string|null}
     */
    public function monthly(Game $game): array
    {
        $appId = (int) $game->steam_appid;

        if ($appId < 1) {
            return ['months' => [], 'recording_since' => null];
        }

        $rows = $this->read(
            'SELECT toStartOfMonth(date)  AS month,
                    avg(players_avg)      AS players_avg,
                    max(players_max)      AS players_peak,
                    sum(players_avg) * 24 AS hours,
                    count()               AS days
               FROM game_players_daily FINAL
              WHERE app_id = {app:UInt32}
              GROUP BY month
              ORDER BY month DESC',
            ['app' => $appId],
        );

        $months = [];

        foreach ($rows as $index => $row) {
            // The month below it in the table, which is the month before it in
            // time. The oldest row has nothing to be compared against and says
            // so with a null rather than a zero — "no change" and "nothing to
            // compare" are different facts.
            $previous = $rows[$index + 1]['players_avg'] ?? null;
            $average = (float) $row['players_avg'];

            $months[] = [
                'month' => Carbon::parse($row['month'])->format('Y-m'),
                'players_avg' => round($average, 1),
                'players_peak' => (int) $row['players_peak'],
                'hours' => (int) round((float) $row['hours']),
                'days' => (int) $row['days'],
                'gain' => $previous === null ? null : round($average - (float) $previous, 1),
                'gain_percent' => $previous === null || (float) $previous <= 0
                    ? null
                    : round((($average - (float) $previous) / (float) $previous) * 100, 1),
            ];
        }

        return [
            'months' => $months,
            'recording_since' => $this->recordingSince($appId),
        ];
    }

    /** The first day the rollup has for this game. */
    private function recordingSince(int $appId): ?string
    {
        $rows = $this->read(
            'SELECT min(date) AS first FROM game_players_daily FINAL WHERE app_id = {app:UInt32}',
            ['app' => $appId],
        );

        $first = $rows[0]['first'] ?? null;

        // An empty table answers with the epoch rather than null.
        if ($first === null || str_starts_with((string) $first, '1970')) {
            return null;
        }

        return $first;
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
            Log::warning('GameTrends query failed', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
