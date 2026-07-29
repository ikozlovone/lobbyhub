<?php

namespace App\Services\Auth\Social;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The nonce that ties a callback to the redirect that started it.
 *
 * Without it, anyone can call the callback URL with a code of their own and
 * sign a visitor into someone else's account. It lives in the cache rather than
 * the session because this API has no session: the frontend is on another
 * origin and carries a token, not a cookie.
 *
 * Single use — consuming deletes it, so a replayed callback finds nothing.
 */
class OAuthState
{
    private const PREFIX = 'auth:oauth-state:';

    private const TTL = 600;

    /** @param  array<string, mixed>  $context */
    public function issue(string $provider, array $context = []): string
    {
        $state = Str::random(40);

        Cache::put(self::PREFIX.$state, ['provider' => $provider] + $context, self::TTL);

        return $state;
    }

    /** @return array<string, mixed>|null the context, or null if unknown, expired or already used */
    public function consume(?string $state, string $provider): ?array
    {
        if ($state === null || $state === '') {
            return null;
        }

        $key = self::PREFIX.$state;
        $context = Cache::get($key);

        Cache::forget($key);

        // A state minted for Discord must not open a Steam callback.
        return is_array($context) && ($context['provider'] ?? null) === $provider ? $context : null;
    }
}
