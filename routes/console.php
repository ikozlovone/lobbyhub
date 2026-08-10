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

/*
 * Facets — the chip counts on a game's page — are heavier than counters (five
 * aggregates per game, three of them linear in the game's size) and change
 * more slowly than what visitors watch. Five minutes puts them halfway inside
 * the ListingCache window they used to live in, so nothing ever sees a game
 * with facets more stale than that, and the cold Postgres pass a first
 * visitor used to pay is gone: the controller reads a JSON column.
 */
Schedule::command('facets:refresh')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Re-rolls today (partial) and yesterday, so graphs stay current intraday.
Schedule::command('stats:rollup --days=2')
    ->hourly()
    ->withoutOverlapping();

/*
 * Tagged cache entries expire on their own; the sets that remember which keys
 * carried a tag do not. Redis is the only store where tags work at all, and it
 * is the only store this command runs against — on anything else it is a no-op,
 * so the schedule does not have to know what CACHE_STORE says.
 *
 * Nightly because it is a scan, and what it reclaims is bookkeeping rather than
 * the payloads themselves.
 */
Schedule::command('cache:prune-stale-tags')
    ->dailyAt('04:10')
    ->withoutOverlapping();
