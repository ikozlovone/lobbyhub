<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The dispatcher itself is cheap; the actual queries run on the queue.
Schedule::command('servers:query')
    ->everyMinute()
    ->withoutOverlapping();

// Votes and measured activity both move constantly; the ranking they feed
// does not need to be more current than this.
Schedule::command('ranking:recompute')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Catalog facets read these; a few minutes of staleness is invisible.
Schedule::command('counters:refresh')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Re-rolls today (partial) and yesterday, so graphs stay current intraday.
Schedule::command('stats:rollup --days=2')
    ->hourly()
    ->withoutOverlapping();
