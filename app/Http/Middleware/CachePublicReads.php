<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Says how long a shared cache may keep this answer.
 *
 * Without it nothing in front of PHP caches anything: Symfony's default is
 * `no-cache, private`, and both nginx's fastcgi_cache and Cloudflare obey it.
 * So this header is what turns the cache in front of the API on — one source of
 * truth that every layer reads, rather than a rule written into each of them.
 *
 * It is worth more than the work it saves. A request to this API costs about
 * the same whatever it does: measured on the deployed app, an endpoint that
 * only reads a handful of rows and one that reads nothing at all both answer in
 * the same 65 ms, because the framework has to boot either way. Caching inside
 * PHP removes the work; only caching in front of it removes the boot.
 *
 * `s-maxage`, not `max-age`. The shared cache keeps the answer; the visitor's
 * browser does not, so a listing they come back to is re-read rather than
 * frozen in their tab. `stale-while-revalidate` lets the shared cache answer
 * from a just-expired copy while it fetches a new one, so the visitor who
 * happens to arrive on the tick does not pay for the refresh.
 *
 * Applied per route rather than to the group, because the group holds routes
 * this must never touch — anything behind a token, the vote status of the
 * person asking, and /servers/live, whose whole job is to be current.
 */
class CachePublicReads
{
    /**
     * How long a shared cache may serve the answer after it goes stale, while
     * it fetches a fresh one behind the visitor's back.
     */
    private const STALE_FOR = 300;

    public function handle(Request $request, Closure $next, int $seconds = 60): Response
    {
        $response = $next($request);

        /*
         * Four ways out, and each is a way this could otherwise leak.
         *
         * A request carrying a token may have been answered with something
         * belonging to that account, whatever the route usually returns. A
         * non-GET is a write. A non-200 is an error or a redirect, and caching
         * either hands the next visitor somebody else's failure. A response
         * that sets a cookie is per-visitor by definition — nginx refuses those
         * on its own, but Cloudflare is a rule away from being told not to.
         */
        if (
            ! $request->isMethod('GET')
            || $response->getStatusCode() !== 200
            || $request->hasHeader('Authorization')
            || $response->headers->has('Set-Cookie')
        ) {
            return $response;
        }

        $response->headers->set(
            'Cache-Control',
            "public, max-age=0, s-maxage={$seconds}, stale-while-revalidate=".self::STALE_FOR,
        );

        return $response;
    }
}
