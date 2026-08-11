<?php

namespace App\Services\Discovery;

/** What one game's sync did, for the command that prints it and the log. */
final readonly class SyncReport
{
    public function __construct(
        public int $found,
        public int $updated,
        public int $created,
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
    ) {}
}
