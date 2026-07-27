<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single monitoring sample. Composite primary key (server_id, recorded_at),
 * so find()/save()-by-key are not usable — insert and read by range instead.
 */
class ServerStat extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'server_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'is_online' => 'boolean',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function scopeBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): void
    {
        $query->whereBetween('recorded_at', [$from, $to]);
    }
}
