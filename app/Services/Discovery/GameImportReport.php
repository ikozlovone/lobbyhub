<?php

namespace App\Services\Discovery;

/** What one pass over gamemonitoring's game catalogue did. */
final readonly class GameImportReport
{
    public function __construct(
        /** Games their catalogue listed. */
        public int $found = 0,
        /** Of those, ones this catalog already has, by appid or by slug. */
        public int $existing = 0,
        /** Rows written. */
        public int $created = 0,
        /** No Steam appid to monitor by, or fewer servers than asked for. */
        public int $skipped = 0,
        public int $pages = 0,
        public float $totalMs = 0.0,
        public ?string $error = null,
    ) {}

    public function complete(): bool
    {
        return $this->error === null;
    }
}
