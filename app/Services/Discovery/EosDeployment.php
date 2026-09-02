<?php

namespace App\Services\Discovery;

use App\Models\Game;
use RuntimeException;

/**
 * One game's Epic Online Services credential triple, resolved once at the top
 * of a sync.
 *
 * The three fields are what Epic's `client_credentials` grant needs — Basic
 * auth's user/password and the deployment the token is scoped to — plus the
 * game slug they were resolved from so an error message names it. They are the
 * values the game's own client ships with; nothing here is anybody's private
 * secret, but they still come from env rather than a code default so a
 * deployment that does not need them does not carry them.
 *
 * A named object rather than three string arguments in every signature because
 * they always travel together and losing one is what makes a 401 look like a
 * broken query.
 */
final readonly class EosDeployment
{
    public function __construct(
        public string $slug,
        public string $deploymentId,
        public string $clientId,
        public string $clientSecret,
    ) {}

    /**
     * Reads `services.eos.deployments.{slug}` and refuses anything blank —
     * which is the whole check the sync would otherwise do next, moved up here
     * so a missing credential fails on the way in with a message that says
     * which env var to set.
     */
    public static function forGame(Game $game): self
    {
        $conf = (array) config('services.eos.deployments.'.$game->slug, []);

        $deploymentId = trim((string) ($conf['deployment_id'] ?? ''));
        $clientId = trim((string) ($conf['client_id'] ?? ''));
        $clientSecret = trim((string) ($conf['client_secret'] ?? ''));

        if ($deploymentId === '' || $clientId === '' || $clientSecret === '') {
            throw new RuntimeException(sprintf(
                'EOS credentials for %s are not configured; set EOS_%s_DEPLOYMENT_ID, EOS_%s_CLIENT_ID and EOS_%s_CLIENT_SECRET',
                $game->slug,
                self::envKey($game->slug),
                self::envKey($game->slug),
                self::envKey($game->slug),
            ));
        }

        return new self($game->slug, $deploymentId, $clientId, $clientSecret);
    }

    /** Slug → env-friendly upper token: `ark-survival-ascended` → `ASA`? no — full. */
    private static function envKey(string $slug): string
    {
        return strtoupper(str_replace('-', '_', $slug));
    }
}
