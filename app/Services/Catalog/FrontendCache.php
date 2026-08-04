<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the frontend that something it has cached is no longer true.
 *
 * Which is now one thing: the game catalog behind its navigation rail. Listings,
 * facets, counters and server pages are all read when a request arrives, so they
 * need no telling — a write here is on the site by the next page view. The rail
 * is cached because it is on every page and changes only when a game is added,
 * edited, or gains its first server, and `games` is the tag for all three.
 *
 * Best effort by design. The frontend may be down, mid-deploy, or simply not
 * configured — none of which is a reason to fail a submission that succeeded.
 * A failure here costs a stale rail for the length of its window, and nothing
 * else.
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
