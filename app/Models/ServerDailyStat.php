<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily rollup. Composite primary key (server_id, date) — write it with
 * upsert() from stats:rollup, never with save()-by-key.
 */
class ServerDailyStat extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'server_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'players_avg' => 'decimal:2',
            'uptime_percent' => 'decimal:2',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
