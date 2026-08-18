<?php

namespace Tests\Unit;

use App\Services\Monitoring\PollingSchedule;
use Tests\TestCase;

class PollingScheduleTest extends TestCase
{
    private PollingSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schedule = new PollingSchedule;
    }

    /**
     * Tier boundaries, using the shipped config: 100+ → 300s, 10+ → 600s,
     * 1+ → 1800s, empty → 3600s.
     */
    public function test_the_interval_follows_how_busy_the_server_is(): void
    {
        $this->assertSame(300, $this->schedule->intervalFor(500, false));
        $this->assertSame(300, $this->schedule->intervalFor(100, false)); // boundary, inclusive
        $this->assertSame(600, $this->schedule->intervalFor(99, false));
        $this->assertSame(600, $this->schedule->intervalFor(10, false));
        $this->assertSame(1800, $this->schedule->intervalFor(9, false));
        $this->assertSame(1800, $this->schedule->intervalFor(1, false));
        $this->assertSame(3600, $this->schedule->intervalFor(0, false));
    }

    /**
     * The floor, stated as its own fact: no server, however full or however
     * paid-for, is knocked on more than once every five minutes.
     */
    public function test_nothing_is_polled_more_often_than_every_five_minutes(): void
    {
        $intervals = array_column(config('monitoring.tiers'), 'interval');
        $intervals[] = (int) config('monitoring.promoted_interval');

        $this->assertGreaterThanOrEqual(300, min($intervals));
    }

    public function test_an_empty_server_is_polled_far_less_often_than_a_busy_one(): void
    {
        // The spread is the whole point of tiering — it is what cuts the load.
        $this->assertSame(
            12,
            intdiv($this->schedule->intervalFor(0, false), $this->schedule->intervalFor(500, false)),
        );
    }

    public function test_a_promoted_server_stays_hot_even_when_empty(): void
    {
        $this->assertSame(
            (int) config('monitoring.promoted_interval'),
            $this->schedule->intervalFor(0, isPromoted: true),
        );
    }

    public function test_an_expired_promotion_gets_no_special_treatment(): void
    {
        // Promotion is decided by the caller — this method only knows the
        // boolean it is given.
        $this->assertSame(3600, $this->schedule->intervalFor(0, isPromoted: false));
    }

    /**
     * The tier a full server lands in and the base the backoff counts from are
     * the same number now. They are still two settings: the fastest tier is a
     * choice about freshness, the base is a choice about how quickly to retry.
     */
    public function test_the_busiest_tier_matches_the_backoff_base(): void
    {
        $this->assertSame((int) config('monitoring.interval'), $this->schedule->intervalFor(500, false));
    }

    public function test_backoff_doubles_with_every_failure(): void
    {
        $interval = (int) config('monitoring.interval');

        $this->assertSame($interval * 2, $this->schedule->backoffFor(1));
        $this->assertSame($interval * 4, $this->schedule->backoffFor(2));
        $this->assertSame($interval * 8, $this->schedule->backoffFor(3));
    }

    public function test_backoff_stops_at_the_ceiling(): void
    {
        $max = (int) config('monitoring.max_interval');

        $this->assertSame($max, $this->schedule->backoffFor(20));
        $this->assertSame($max, $this->schedule->backoffFor(65535));
    }

    /**
     * A failing server reports zero players; if backoff used the tier it would
     * treat every outage as "quiet" and skip the fast early retries.
     */
    public function test_backoff_ignores_the_tiers(): void
    {
        $this->assertSame(
            (int) config('monitoring.interval') * 2,
            $this->schedule->backoffFor(1),
        );
    }

    public function test_expected_hourly_queries_matches_the_tier(): void
    {
        $this->assertSame(12.0, $this->schedule->expectedHourlyQueries(500, false));
        $this->assertSame(1.0, $this->schedule->expectedHourlyQueries(0, false));
    }
}
