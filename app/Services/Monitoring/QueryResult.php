<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Carbon;

/**
 * Normalized answer from any protocol driver — this is what the job writes
 * into `servers` and `server_stats`, regardless of the game.
 *
 * Fields a given protocol cannot report stay null; the job skips those columns
 * rather than overwriting them with emptiness.
 */
final readonly class QueryResult
{
    public function __construct(
        public int $playersOnline = 0,
        public int $playersMax = 0,
        public ?string $version = null,
        public ?string $map = null,
        public ?string $motd = null,
        public ?int $latencyMs = null,
        public ?string $ipAddress = null,
        /** Port players connect to, when the server reports one. */
        public ?int $gamePort = null,
        /** Rust: last server start, in practice the last wipe. */
        public ?Carbon $wipedAt = null,
        /** Rust: players waiting in the join queue. */
        public ?int $playersQueued = null,
    ) {}
}
