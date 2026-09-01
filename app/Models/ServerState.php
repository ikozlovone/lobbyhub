<?php

namespace App\Models;

use App\Enums\ServerStatus;
use Database\Factories\ServerStateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The hot half of a server row — everything the monitor rewrites.
 *
 * Kept apart from `Server` so that the ten-minute Steam sweep does not
 * fragment indexes that only the cold catalog cares about. Partitioned by
 * `game_id` on Postgres so a per-game listing reaches one physical table
 * rather than scanning 150k rows across every game.
 *
 * The PK is composite — `(game_id, server_id)` — because Postgres requires
 * the partition key to be in the primary key. Eloquent supports single-column
 * PKs only, so `server_id` is what's exposed here and every partition-pruning
 * read explicitly adds `where game_id = ...` too. See the design doc for the
 * pattern.
 */
class ServerState extends Model
{
    /** @use HasFactory<ServerStateFactory> */
    use HasFactory;

    protected $table = 'server_states';

    /**
     * Eloquent needs a single-column PK for `find`, `save` and `getKey` to
     * behave. `server_id` is the useful side of the composite; `game_id` is
     * known from the parent at every call site and must be added by hand to
     * every query that expects Postgres to prune.
     */
    protected $primaryKey = 'server_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ServerStatus::class,
            'vac_enabled' => 'boolean',
            'wiped_at' => 'datetime',
            'last_queried_at' => 'datetime',
            'last_online_at' => 'datetime',
            'last_offline_at' => 'datetime',
            'next_query_at' => 'datetime',
            'steam_seen_at' => 'datetime',
            'uptime_percent' => 'decimal:2',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
