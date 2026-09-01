<?php

namespace App\Services\Discovery;

/** What one game's pass over gamemonitoring's list did. */
final readonly class GameMonitoringReport
{
    public function __construct(
        /** Rows their API handed over. */
        public int $found = 0,
        /** Of those, ones the catalog already holds. */
        public int $matched = 0,
        /** Of those matched, ones that did not carry the mark yet. */
        public int $marked = 0,
        /** Rows written, because no server here answered to that address. */
        public int $created = 0,
        /**
         * Rows neither matched nor written.
         *
         * Two causes, both worth seeing above zero: an address that could not
         * be read at all, and one belonging to a server somebody here deleted
         * — which must not come back through the side door of a competitor
         * still listing it.
         */
        public int $skipped = 0,
        public int $pages = 0,
        public float $totalMs = 0.0,
        /**
         * Why the walk stopped early, if it did.
         *
         * A pass over twenty pages that dies on the fourth has still marked
         * and written three pages' worth, and those writes are committed. So
         * the failure travels back with the counts rather than instead of
         * them: reporting zero for a run that changed the database is the one
         * answer that cannot be acted on.
         */
        public ?string $error = null,
        /**
         * The offset the next page would have been. Only interesting when the
         * walk stopped early, where it is what `--from` takes to continue.
         */
        public int $nextOffset = 0,
    ) {}

    /** Whether the whole of their list was read. */
    public function complete(): bool
    {
        return $this->error === null;
    }
}
