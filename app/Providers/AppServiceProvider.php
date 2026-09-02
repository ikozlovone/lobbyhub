<?php

namespace App\Providers;

use App\Models\Game;
use App\Observers\GameObserver;
use App\Services\Discovery\EosClient;
use App\Services\Discovery\GameMonitoringClient;
use App\Services\Geo\GeoResolver;
use App\Services\Geo\MaxMindGeoResolver;
use App\Services\Geo\NullGeoResolver;
use App\Services\Http\SharedCache;
use App\Services\Stats\ClickHouseClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Geo lookups degrade to a no-op until a GeoLite2 file is in place.
        // City is preferred and also carries country data; Country is the fallback.
        $this->app->singleton(GeoResolver::class, function () {
            foreach ((array) config('monitoring.geoip.databases', []) as $path) {
                if (is_string($path) && is_file($path)) {
                    return new MaxMindGeoResolver($path);
                }
            }

            return new NullGeoResolver;
        });

        // ClickHouse holds the per-server player-count history graphs read
        // from. A single client instance per process is fine — everything
        // beneath uses Laravel's shared Http:: manager, and there is no
        // per-request state on the client itself.
        $this->app->singleton(ClickHouseClient::class, function () {
            $cfg = (array) config('services.clickhouse', []);

            return new ClickHouseClient(
                host: $cfg['host'] ?? null,
                port: (int) ($cfg['port_http'] ?? 8123),
                database: (string) ($cfg['database'] ?? 'lobbyhub_stats'),
                username: (string) ($cfg['username'] ?? 'default'),
                password: (string) ($cfg['password'] ?? ''),
            );
        });

        // The competitor's list reader. Stateless; the pass that uses it holds
        // everything that is per-run.
        $this->app->singleton(GameMonitoringClient::class, fn () => new GameMonitoringClient(
            url: rtrim((string) config('services.gamemonitoring.url'), '/'),
            pageSize: (int) config('services.gamemonitoring.page_size', 1000),
            timeout: (int) config('services.gamemonitoring.timeout', 30),
            pauseMs: (int) config('services.gamemonitoring.pause_ms', 250),
            attempts: (int) config('services.gamemonitoring.attempts', 4),
        ));

        // Epic Online Services matchmaking client — the source of session
        // lists for games with no Steam server registration (ARK: SA today).
        // Singleton so a long-running worker keeps its per-deployment token
        // cache warm across jobs; a one-off CLI just discards it at exit.
        $this->app->singleton(EosClient::class, fn () => new EosClient(
            baseUrl: rtrim((string) config('services.eos.base_url'), '/'),
            timeout: (int) config('services.eos.timeout', 30),
            pauseMs: (int) config('services.eos.pause_ms', 250),
            attempts: (int) config('services.eos.attempts', 4),
            pageSize: (int) config('services.eos.page_size', 200),
        ));

        // The nginx cache in front of this app, for the one caller that has to
        // tell it to forget something. Stateless — a path and the layout of
        // the directory tree under it.
        $this->app->singleton(SharedCache::class, fn () => new SharedCache(
            path: config('services.nginx.cache_path'),
            levels: (string) config('services.nginx.cache_levels', '1:2'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A new game gets a `server_states` partition the moment it is
        // created. See the observer for why lazy creation would be worse.
        Game::observe(GameObserver::class);

        /*
         * Every absolute URL this app hands out is built from APP_URL, not from
         * the address the request arrived on.
         *
         * Laravel derives them from the request root by default, which is right
         * exactly once: when the only way in is the public hostname. It is not
         * here. The frontend reads this API over loopback to keep its renders off
         * the network, artisan runs with no request at all, and a health check
         * arrives as 127.0.0.1 — and a "Sign in with Steam" button built during
         * any of those points the visitor's browser at their own machine.
         *
         * The scheme has to be forced alongside the root: forceRootUrl keeps the
         * host but takes the scheme from the request, and a loopback request is
         * plain HTTP however the site is served.
         *
         * Not in local development, where the request is the better source: there
         * is only one way in, and APP_URL habitually omits the port that `artisan
         * serve` is actually listening on.
         */
        if (! $this->app->environment('local') && is_string($root = config('app.url')) && $root !== '') {
            URL::forceRootUrl($root);

            if (str_starts_with($root, 'https://')) {
                URL::forceScheme('https');
            }
        }

        /*
         * Generous for a public read-only catalog, but it stops a single client
         * from turning the listing endpoints into a load test.
         *
         * A page view is not one request: the shell fetches the game, its
         * listing and its recent votes, and the live layer then re-reads player
         * counts for as long as the tab is open. The frontend build is heavier
         * still — it prerenders every game and reads this API from one address
         * as fast as it can. A budget tuned to one-request-per-view fails both,
         * and the writes worth rationing (votes, submissions, sign-in codes)
         * have their own much tighter limiters below.
         */
        RateLimiter::for('api', function (Request $request) {
            /*
             * The site's own renders are not a client.
             *
             * The frontend runs on this machine and reads the API over loopback,
             * so every server-rendered page arrives from one address. One click
             * in the games menu is not one request either: Next prefetches every
             * link on the page it is opening, three segment requests apiece —
             * about a hundred and fifty renders in a burst, each of them reading
             * the catalog. Against a per-visitor budget that is the whole site
             * counting as a single visitor, and the first thing it costs is not
             * a refused request but a half-written page: a 429 inside a render
             * aborts the stream the browser is already reading, which it reports
             * as "Connection closed".
             *
             * Nothing is given away by exempting them. These requests cannot be
             * made from outside — the loopback listener is bound to 127.0.0.1 —
             * and the visitors who cause them are already counted at the front
             * door by the frontend's own limits.
             */
            if (in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
                return Limit::none();
            }

            return Limit::perMinute(600)->by($request->user()?->id ?: $request->ip());
        });

        // Votes are the one public write, so they get their own, much tighter
        // budget: the daily unique index stops repeat votes for one server, this
        // stops a script walking the whole catalog.
        RateLimiter::for('votes', fn (Request $request) => Limit::perHour(30)->by($request->ip()));

        // Every submission makes us query an address the submitter chose, so
        // the budget is small enough that the form can never be used to point
        // our monitor at somebody else's network in bulk.
        RateLimiter::for('submissions', fn (Request $request) => Limit::perHour(10)->by($request->ip()));

        // The refresh button. Also makes us query a real machine, but a listed
        // one rather than an address a stranger typed, and there is a per-server
        // cooldown behind it — this only stops one client walking the catalog.
        RateLimiter::for('refreshes', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));

        // Sending mail on demand to an address a stranger typed. Limited per
        // address as well as per network, so one client cannot walk a list of
        // mailboxes, and one mailbox cannot be flooded from many clients.
        RateLimiter::for('auth-codes', fn (Request $request) => [
            Limit::perHour(5)->by((string) $request->input('email')),
            Limit::perHour(20)->by($request->ip()),
        ]);

        // Guessing budget across addresses. The per-code attempt counter is the
        // real defence; this stops the same client trying a thousand codes
        // against a thousand addresses.
        RateLimiter::for('auth-verify', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // The contact form sends mail to our own inbox on demand — cheap per
        // request, but the mailbox on the other end is one place, so the ceiling
        // is what stops a mistake or a bad actor from filling it. Per-hour by
        // network, plus a per-address budget so somebody rotating IPs cannot
        // burn all of them writing as the same person.
        RateLimiter::for('contact', fn (Request $request) => [
            Limit::perHour(5)->by($request->ip()),
            Limit::perHour(20)->by((string) $request->input('email')),
        ]);
    }
}
