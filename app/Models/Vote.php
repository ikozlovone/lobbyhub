<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vote extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['ip_hash'];

    /**
     * `vote_day` is deliberately not cast to a date.
     *
     * The cast serializes writes as 'Y-m-d H:i:s'. Postgres normalizes that back
     * into its DATE column, but sqlite compares it as text — so the same code
     * would dedupe votes in production and silently stop doing so in tests.
     * Kept as a plain 'Y-m-d' string, it behaves identically on both.
     */
    protected function casts(): array
    {
        return [
            'rewarded_at' => 'datetime',
        ];
    }

    public static function today(): string
    {
        return now()->toDateString();
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Keyed hash, not a plain digest: the address space is small enough to brute
     * force a bare sha256 of an IP in seconds.
     */
    public static function hashIp(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    public function scopeSince(Builder $query, \DateTimeInterface $from): void
    {
        $query->where('vote_day', '>=', $from);
    }

    public function scopeUnrewarded(Builder $query): void
    {
        $query->whereNull('rewarded_at');
    }
}
