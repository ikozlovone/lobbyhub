<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Steam's own list, for every game that has an app id — in two passes, because
 * one pass at the cadence the busy servers deserve does not fit in a key.
 *
 * Reading everything every five minutes costs around four hundred requests a
 * pass: twelve of the forty-five games hold more servers than one response
 * carries, and reaching all of them is eighteen requests for Valheim and
 * sixty-eight for Counter-Strike 2. That is about 115 000 calls a day against a
 * key rated for 100 000.
 *
 * Splitting it by whether anyone is playing costs almost nothing and matches
 * the tiers that already exist. Occupied servers are a small slice — Rust's are
 * one request, Counter-Strike's twenty-six against its sixty-eight — and they
 * are the ones sitting on the five and ten minute tiers. Empty servers are on
 * the hour, so reading them every half hour is still ahead of what they are
 * owed. Together: roughly 42 000 calls a day.
 *
 * Ahead of `servers:query` in the file because that is the order they matter
 * in: the sweeps are what make most of the catalog need no packet at all, and
 * the poller below is left with what they did not cover — which now includes
 * servers that emptied since the last full pass, and those answer in
 * milliseconds rather than timing out.
 */
Schedule::command('steam:sync --populated')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('steam:sync')
    ->everyThirtyMinutes()
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
