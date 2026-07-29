<?php

namespace App\Services\Auth\Social;

use App\Services\Auth\Social\Exceptions\SocialAuthFailed;

/**
 * Google OAuth 2.
 *
 * The token response carries an `id_token` we deliberately ignore: verifying a
 * JWT means fetching and caching Google's signing keys, and the userinfo
 * endpoint answers the same question over a channel we already trust — a TLS
 * request made server-side with a token we just received.
 */
class GoogleProvider extends OAuth2Provider
{
    public function __construct()
    {
        parent::__construct('google');
    }

    protected function authorizeUrl(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    protected function tokenUrl(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    protected function profileUrl(): string
    {
        return 'https://openidconnect.googleapis.com/v1/userinfo';
    }

    protected function scopes(): array
    {
        return ['openid', 'email', 'profile'];
    }

    protected function mapUser(array $profile): SocialUser
    {
        $id = $profile['sub'] ?? null;

        if (! is_string($id) || $id === '') {
            throw SocialAuthFailed::rejected($this->label(), 'profile has no subject');
        }

        return new SocialUser(
            provider: 'google',
            id: $id,
            nickname: $this->string($profile['name'] ?? null) ?? $this->string($profile['given_name'] ?? null),
            email: $this->string($profile['email'] ?? null),
            // Google is strict about this flag; an unverified address here is
            // one that must not be allowed to claim an existing account.
            emailVerified: ($profile['email_verified'] ?? false) === true,
            avatarUrl: $this->string($profile['picture'] ?? null),
        );
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
