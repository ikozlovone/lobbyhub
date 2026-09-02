<?php

namespace App\Services\Discovery;

/** What one EOS sync did, for the command that prints it and the log. */
final readonly class EosSyncReport
{
    public function __construct(
        /** Total session rows EOS returned across every page, before dedup. */
        public int $found,
        /** Distinct addresses after dedup — the number the sync could touch. */
        public int $distinct,
        public int $updated,
        public int $created,
        /** How many EOS pages were fetched. */
        public int $pages,
        /**
         * Servers EOS handed over that the sync would have added if
         * `monitoring.eos_create_new_servers` were on. On a frozen catalog this
         * is what "everything the sync passed on" looks like.
         */
        public int $skipped = 0,
        /**
         * Wall milliseconds by phase. Four numbers rather than one, same reason
         * the Steam side splits them: each has a different fix.
         *
         *  - `http` is the wait on Epic's front — moves only with concurrency or a
         *    wider page size.
         *  - `rows` is decoding sessions and building the state rows.
         *  - `db` is the writes.
         *  - `existing` is the address map loaded before any of it.
         */
        public float $totalMs = 0.0,
        public float $httpMs = 0.0,
        public float $rowsMs = 0.0,
        public float $dbMs = 0.0,
        public float $existingMs = 0.0,
    ) {}
}
