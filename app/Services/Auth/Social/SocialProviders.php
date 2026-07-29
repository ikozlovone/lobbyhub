<?php

namespace App\Services\Auth\Social;

use App\Services\Auth\Social\Contracts\SocialProvider;
use Illuminate\Contracts\Container\Container;

/**
 * Which providers exist, and which of them are actually usable.
 *
 * The same shape as ServerQueryManager: a key picks a driver. Whether a driver
 * has credentials is a separate question from whether it exists, and the two
 * have separate answers here — `all()` for the dialog, which shows every method
 * we support, and `for()` for the redirect, which must refuse the ones that
 * would only bounce a visitor onto a provider's error page.
 */
class SocialProviders
{
    /** @var array<string, class-string<SocialProvider>> */
    private const DRIVERS = [
        'steam' => SteamProvider::class,
        'discord' => DiscordProvider::class,
        'google' => GoogleProvider::class,
    ];

    public function __construct(private Container $container) {}

    public function for(string $provider): ?SocialProvider
    {
        $driver = self::DRIVERS[$provider] ?? null;

        if ($driver === null) {
            return null;
        }

        $instance = $this->container->make($driver);

        return $instance->isConfigured() ? $instance : null;
    }

    /**
     * Every provider we support, in the order the dialog shows them.
     *
     * Including the ones with no credentials: a visitor deciding how to sign in
     * should see the whole menu, and a method that is merely not switched on yet
     * says something truer than a gap where it used to be. Ask each one's
     * `isConfigured()` for whether it can be clicked.
     *
     * @return array<string, SocialProvider>
     */
    public function all(): array
    {
        $all = [];

        foreach (self::DRIVERS as $key => $driver) {
            $all[$key] = $this->container->make($driver);
        }

        return $all;
    }
}
