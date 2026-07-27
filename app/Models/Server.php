<?php

namespace App\Models;

use App\Enums\ServerStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Server extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['claim_token'];

    protected function casts(): array
    {
        return [
            'status' => ServerStatus::class,
            'uptime_percent' => 'decimal:2',
            'rating_avg' => 'decimal:2',
            'is_active' => 'boolean',
            'wiped_at' => 'datetime',
            'last_queried_at' => 'datetime',
            'last_online_at' => 'datetime',
            'next_query_at' => 'datetime',
            'claimed_at' => 'datetime',
            'promoted_until' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(GameVersion::class, 'game_version_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** The verified owner, if the server has been claimed. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function modes(): BelongsToMany
    {
        return $this->belongsToMany(GameMode::class);
    }

    /** Raw monitoring samples (short retention). */
    public function stats(): HasMany
    {
        return $this->hasMany(ServerStat::class);
    }

    /** Daily rollups (kept forever). */
    public function dailyStats(): HasMany
    {
        return $this->hasMany(ServerDailyStat::class);
    }

    /** Qualified: these scopes are used inside joins, where `is_active` is ambiguous. */
    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_active'), true);
    }

    public function scopeOnline(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), ServerStatus::Online);
    }

    public function scopePromoted(Builder $query): void
    {
        $query->where('promoted_until', '>', now());
    }

    public function isClaimed(): bool
    {
        return $this->user_id !== null && $this->claimed_at !== null;
    }

    public function isPromoted(): bool
    {
        return $this->promoted_until !== null && $this->promoted_until->isFuture();
    }

    /**
     * Port the monitor sends queries to.
     *
     * Falls back to the server's own port, not the game's default: for almost
     * every protocol the query port equals the game port, and reaching for the
     * game default would silently query the wrong port on any server that does
     * not sit on it. `games.default_query_port` stays a submission-form hint.
     */
    public function queryPort(): int
    {
        return $this->query_port ?? $this->port;
    }

    /** Address a player connects to — not necessarily the one we query. */
    public function address(): string
    {
        return $this->host.':'.($this->game_port ?? $this->port);
    }
}
