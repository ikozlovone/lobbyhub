<?php

namespace App\Models;

use App\Enums\QueryProtocol;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'links' => 'array',
            'query_protocol' => QueryProtocol::class,
            'is_active' => 'boolean',
            'has_versions' => 'boolean',
            'stats_synced_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function modes(): HasMany
    {
        return $this->hasMany(GameMode::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(GameVersion::class);
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_active'), true);
    }

    /** Port the monitor sends queries to; falls back to the game port. */
    public function queryPort(): int
    {
        return $this->default_query_port ?? $this->default_port;
    }
}
