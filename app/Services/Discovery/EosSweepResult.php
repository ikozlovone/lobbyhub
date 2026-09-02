<?php

namespace App\Services\Discovery;

/**
 * What one EOS sweep came back with, and what it cost.
 *
 * Counts only: sessions themselves were handed to the caller as they arrived.
 * `found` is what EOS listed across every page; `distinct` is after the
 * address dedup that makes overlapping criteria safe (and matches the count
 * the sync will really touch). Their difference is how much the walk was
 * repeating itself — zero on a criteria-free sweep, non-zero as soon as
 * region axes are layered on top.
 */
final readonly class EosSweepResult
{
    public function __construct(
        public int $found,
        public int $distinct,
        public int $pages,
        /**
         * Rows dropped by the caller's address filter, before anything was
         * built from them. On a frozen catalog this is most of the response.
         */
        public int $skipped = 0,
        /**
         * Wall milliseconds spent between the first page request and the last
         * page's read — the HTTP portion of the sweep, and the one lever an
         * operator has if throughput is not enough (more concurrency, if we
         * ever add it, or a wider `page_size`).
         */
        public float $httpMs = 0.0,
    ) {}
}
