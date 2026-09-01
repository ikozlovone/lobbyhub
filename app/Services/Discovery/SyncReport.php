<?php

namespace App\Services\Discovery;

/** What one game's sync did, for the command that prints it and the log. */
final readonly class SyncReport
{
    public function __construct(
        public int $found,
        public int $updated,
        public int $created,
        /**
         * Servers whose reading was due when Steam listed them — the count of
         * player numbers this run actually took, rather than of rows written
         * anywhere. It used to be one `server_stats` row each; that table is
         * retired and the history now comes from the Go sweeper's ClickHouse
         * writes, so nothing here writes a sample any more.
         */
        public int $sampled,
        public int $requests,
        /** Buckets Steam would not finish handing over. Anything above zero is a gap. */
        public int $truncated,
        /** Buckets a network failure kept us from reading. Also a gap, different cause. */
        public int $unreachable = 0,
        /**
         * Servers Steam handed over that the sweep would have added if
         * monitoring.steam_create_new_servers were on. Not a failure — it is
         * what a frozen catalog looks like from the sweep's side.
         */
        public int $skipped = 0,
        /**
         * Steam rows that resolved to a catalog row already written this run.
         *
         * Not a failure, but not nothing either: it counts servers whose stored
         * `game_port` disagrees with their `port`, which is how one row comes to
         * answer to two addresses that Steam lists separately. Anything much
         * above zero means the catalog is matching more loosely than it should.
         */
        public int $duplicated = 0,
        /**
         * Where the wall clock went, in milliseconds.
         *
         * Four numbers rather than one, because "the sweep took four seconds"
         * has four completely different fixes behind it and no way to tell
         * which. `steam` is the wait on the API and moves only with more keys
         * or more concurrency. `rows` is decoding and building, and moves with
         * how early unwanted rows are dropped. `db` is the writes. `existing`
         * is the address map loaded before any of it, which is the one that
         * grows with the catalog rather than with the game.
         */
        public float $totalMs = 0.0,
        public float $steamMs = 0.0,
        public float $rowsMs = 0.0,
        public float $dbMs = 0.0,
        public float $existingMs = 0.0,
    ) {}
}
