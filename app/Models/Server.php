<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Server extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['claim_token'];

    protected function casts(): array
    {
        return [
            'rating_avg' => 'decimal:2',
            'is_active' => 'boolean',
            'details' => 'array',
            'details_synced_at' => 'datetime',
            'claimed_at' => 'datetime',
            'promoted_until' => 'datetime',
            // Competitor coverage — see GameMonitoringSync. Null is "never
            // matched there", which is also every row's answer before the
            // first pass; a date is when it was first matched, not last.
            'gamemonitoring_seen_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * A public URL for a server, from a name we do not control.
     *
     * Server names are wild — emoji, unicode, pure decoration — so the address
     * is always appended: it keeps the slug unique and stops it from coming out
     * empty. The counter afterwards covers the rest: one machine can hold two
     * games' servers, and a soft-deleted row still owns its slug.
     */
    public static function slugFor(string $name, string $host, int $port): string
    {
        $suffix = str_replace([':', '.'], '-', $host).'-'.$port;
        $base = Str::limit(Str::slug($name), 60, '');
        $base = $base === '' ? $suffix : "{$base}-{$suffix}";

        $slug = $base;

        for ($n = 2; static::withTrashed()->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
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

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    /**
     * The hot half of the row — status, players, MOTD, schedule, everything
     * the monitor rewrites. Lives in `server_states`, partitioned by game.
     *
     * Lazy loading this crosses every partition because the query is by
     * `server_id` alone. That is tolerable for a single-server view and
     * intolerable for listings; listings must JOIN `server_states` on both
     * `server_id` AND `game_id`, and pass the same `where game_id` to any
     * eager load, so Postgres can prune to one partition.
     */
    public function state(): HasOne
    {
        return $this->hasOne(ServerState::class, 'server_id', 'id');
    }

    /** Load `state` with the `game_id` predicate that lets Postgres prune. */
    public function loadState(): static
    {
        return $this->load(['state' => fn ($q) => $q->where('game_id', $this->game_id)]);
    }

    /**
     * Refresh both the row and its state, so a test that saved and re-reads
     * this model sees whatever the writer just persisted, on both sides of
     * the split.
     *
     * The base `refresh()` reloads attributes from the row's own table only.
     * Every hot field the monitor writes now lives on `server_states`, and a
     * test that expects `$server->refresh()->players_online` to be current
     * would otherwise silently read stale data.
     */
    public function refresh()
    {
        $result = parent::refresh();

        if ($this->exists) {
            $this->loadState();
        }

        return $result;
    }

    /**
     * Magic accessors for the hot fields that used to live on this table.
     *
     * These are a compatibility shim: prod code eager-loads state and reads
     * `$server->state->players_online` explicitly. The tests, of which there
     * are many, are all written against the pre-split shape — rewriting
     * every assertion would be pure toil. So the model routes the old names
     * through the state relation, and the tests read what they used to.
     *
     * A missing state relation triggers a lazy load — one query, no
     * partition pruning. Under a test that is a couple of milliseconds; in
     * prod every code path loads state ahead of time, so this branch is not
     * meant to be taken. Deleted with the columns in phase 6.
     */
    public function __get($key)
    {
        // Only redirect saved models: an unsaved `Server::make(['players_online'
        // => 5])` should keep answering 5, which is what tests around the
        // probe/factory paths still expect. Once the row exists, the state
        // relation is the truth.
        if (array_key_exists($key, self::STATE_ATTRIBUTES) && $this->exists) {
            $state = $this->stateForAccessor();

            return $state?->{$key} ?? self::STATE_ATTRIBUTES[$key];
        }

        return parent::__get($key);
    }

    /** @var array<string, mixed> field name → default when state is missing */
    private const STATE_ATTRIBUTES = [
        'status' => null,
        'players_online' => 0,
        'players_max' => 0,
        'players_queued' => 0,
        'bots' => null,
        'vac_enabled' => null,
        'map' => null,
        'reported_version' => null,
        'motd' => null,
        'wiped_at' => null,
        'steam_id' => null,
        'game_port' => null,
        'latency_ms' => null,
        'last_queried_at' => null,
        'last_online_at' => null,
        'last_offline_at' => null,
        'next_query_at' => null,
        'failed_queries_count' => 0,
        'steam_seen_at' => null,
        'uptime_percent' => null,
    ];

    private function stateForAccessor(): ?ServerState
    {
        if ($this->relationLoaded('state')) {
            return $this->state;
        }

        // No state loaded and no id to look one up by — a `Server::make`
        // for a probe or a factory `make()`. Return null and let the accessor
        // default handle it.
        if (! $this->exists || $this->game_id === null) {
            return null;
        }

        $this->loadState();

        return $this->state;
    }

    /** Qualified: this scope runs inside JOINs, where `is_active` is ambiguous. */
    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_active'), true);
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

    /**
     * Address a player connects to — not necessarily the one we query.
     *
     * The reported `game_port` lives on the state row; falls back to the
     * submitted `port` if the state isn't loaded or the server never reported
     * one. Callers that show the address either JOIN state or eager-load it.
     */
    public function address(): string
    {
        $reported = $this->relationLoaded('state') && $this->state
            ? $this->state->game_port
            : null;

        return $this->host.':'.($reported ?? $this->port);
    }
}
