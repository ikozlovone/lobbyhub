<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the frontend that something it has cached is no longer true.
 *
 * The catalog is served from page shells cached for minutes — long enough that
 * an owner who adds a server and goes straight back to the listing would not
 * find it, or the count beside the game, or their own server's page. The window
 * is the fallback, not the mechanism; this is the mechanism.
 *
 * Best effort by design. The frontend may be down, mid-deploy, or simply not
 * configured — none of which is a reason to fail a submission that succeeded.
 * A failure here costs freshness for the length of the cache window, which is
 * exactly where we were without it.
 */
class FrontendCache
{
    /** Short: this sits inside a request somebody is waiting on. */
    private const TIMEOUT = 2;

    public function invalidate(string ...$tags): void
    {
        $url = config('services.frontend.revalidate_url');
        $secret = config('services.frontend.revalidate_secret');

        if (! $url || ! $secret || $tags === []) {
            return;
        }

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withHeaders(['x-revalidate-secret' => $secret])
                ->post($url, ['tags' => array_values(array_unique($tags))]);

            if ($response->failed()) {
                Log::warning('Frontend revalidation refused', [
                    'status' => $response->status(),
                    'tags' => $tags,
                ]);
            }
        } catch (Throwable $exception) {
            // Logged rather than swallowed silently: freshness quietly degrading
            // back to the cache window is the kind of thing nobody notices until
            // somebody asks why their server is not showing up.
            Log::warning('Frontend revalidation failed', [
                'message' => $exception->getMessage(),
                'tags' => $tags,
            ]);
        }
    }
}
