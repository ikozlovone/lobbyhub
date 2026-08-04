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
    ) {}
}
