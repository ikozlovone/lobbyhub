<?php

namespace App\Services\Auth\Social;

/**
 * One provider profile, normalized — the social equivalent of QueryResult.
 *
 * `emailVerified` is not decoration. It decides whether an incoming provider
 * account is allowed to attach itself to an existing LobbyHub account that
 * already uses that address, which is the difference between convenience and
 * a takeover: a provider that lets anyone claim any address would otherwise
 * hand over accounts to whoever asks.
 */
final readonly class SocialUser
{
    public function __construct(
        public string $provider,
        public string $id,
        public ?string $nickname = null,
        public ?string $email = null,
        public bool $emailVerified = false,
        public ?string $avatarUrl = null,
    ) {}

    /** The address we are willing to treat as proof of identity, if any. */
    public function trustedEmail(): ?string
    {
        return $this->emailVerified ? $this->email : null;
    }
}
