<?php

namespace App\Services\Discovery;

/**
 * What one game's sweep came back with, and what it cost.
 *
 * Counts only: the servers themselves were handed to the caller as they
 * arrived, because a game the size of Counter-Strike does not fit in a
 * worker's memory twice over.
 *
 * `truncated` is the number of buckets still full after every axis had been
 * applied — servers Steam holds and would not hand over. It is carried out to
 * the caller rather than logged and forgotten, because a sweep that quietly
 * returns nine tenths of a catalog looks exactly like one that returns all of
 * it.
 */
final readonly class SweepResult
{
    public function __construct(
        public int $found,
        public int $requests,
        public int $truncated,
        /** Buckets a network failure kept us from reading at all. */
        public int $unreachable = 0,
        /**
         * Rows dropped by the caller's address filter, before anything was built
         * from them. Counted rather than ignored: it is the difference between
         * what Steam is offering and what the catalog is willing to take, and on
         * a frozen catalog that difference is most of the response.
         */
        public int $skipped = 0,
        /**
         * Wall milliseconds spent waiting on Steam, and nothing else — not the
         * JSON decode, not the rows. Separated because these are the two costs
         * with completely different fixes: more keys and more concurrency move
         * the first, and nothing done locally moves it at all.
         */
        public float $httpMs = 0.0,
    ) {}
}
