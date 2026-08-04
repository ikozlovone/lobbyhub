<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Steam's own list, for every game that has an app id.
 *
 * Five minutes, which is the fastest cadence any tier asks for — so a server on
 * the hottest tier is never waiting on this, and one on the quiet tier is
 * simply read more often than it is recorded.
 *
 * Ahead of `servers:query` in the file because that is the order they matter
 * in: the sweep is what makes most of the catalog not need a packet at all, and
 * the poller below is left with what it did not cover.
 */
Schedule::command('steam:sync')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// The dispatcher itself is cheap; the actual queries run on the queue.
Schedule::command('servers:query')
    ->everyMinute()
    ->withoutOverlapping();

// Votes and measured activity both move constantly; the ranking they feed
// does not need to be more current than this.
Schedule::command('ranking:recompute')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Four aggregate queries, and the numbers they feed sit next to live ones in
// the same hero — five minutes of drift was visible as the two disagreeing.
Schedule::command('counters:refresh')
    ->everyMinute()
    ->withoutOverlapping();

// Re-rolls today (partial) and yesterday, so graphs stay current intraday.
Schedule::command('stats:rollup --days=2')
    ->hourly()
    ->withoutOverlapping();
