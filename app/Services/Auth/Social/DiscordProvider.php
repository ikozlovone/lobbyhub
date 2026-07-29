<?php

namespace App\Services\Auth\Social;

use App\Services\Auth\Social\Exceptions\SocialAuthFailed;

/**
 * Discord OAuth 2.
 *
 * `identify` alone would be enough to sign someone in; `email` is asked for so
 * an account that already exists under that address is recognised instead of
 * being forked into a second one.
 */
class DiscordProvider extends OAuth2Provider
{
    public function __construct()
    {
        parent::__construct('discord');
    }

    protected function authorizeUrl(): string
    {
        return 'https://discord.com/oauth2/authorize';
    }

    protected function tokenUrl(): string
    {
        return 'https://discord.com/api/oauth2/token';
    }

    protected function profileUrl(): string
    {
        return 'https://discord.com/api/users/@me';
    }

    protected function scopes(): array
    {
        return ['identify', 'email'];
    }

    protected function mapUser(array $profile): SocialUser
    {
        $id = $profile['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw SocialAuthFailed::rejected($this->label(), 'profile has no id');
        }

        return new SocialUser(
            provider: 'discord',
            id: $id,
            // `global_name` is the display name Discord moved to in 2023;
            // `username` is what accounts that never migrated still have.
            nickname: $this->string($profile['global_name'] ?? null) ?? $this->string($profile['username'] ?? null),
            email: $this->string($profile['email'] ?? null),
            emailVerified: ($profile['verified'] ?? false) === true,
            avatarUrl: $this->avatar($id, $this->string($profile['avatar'] ?? null)),
        );
    }

    private function avatar(string $id, ?string $hash): ?string
    {
        // Avatars are hashes, not URLs, and an animated one is a `.gif` behind
        // the same hash with an `a_` prefix.
        return $hash === null
            ? null
            : "https://cdn.discordapp.com/avatars/{$id}/{$hash}.".(str_starts_with($hash, 'a_') ? 'gif' : 'png');
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
