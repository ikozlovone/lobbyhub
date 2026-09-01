<?php

use App\Http\Controllers\Api\Auth\EmailCodeController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\Auth\SocialAuthController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\GameChartController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\ServerHistoryController;
use App\Http\Controllers\Api\ServerSubmissionController;
use App\Http\Controllers\Api\SitemapController;
use App\Http\Controllers\Api\VoteController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public catalog API
|--------------------------------------------------------------------------
|
| Consumed by the Next.js frontend. Route keys are slugs (see each model's
| getRouteKeyName), so these URLs mirror the public site structure.
|
*/

/*
 * `cache.public:N` is how long a shared cache — nginx in front of PHP, and
 * Cloudflare in front of that — may keep the answer. It is on the public reads
 * one at a time rather than on the group, because the group also holds the
 * routes that must never be shared: anything behind a token, the vote status of
 * whoever is asking, and `servers/live`.
 *
 * The windows follow how fast each thing actually moves, and they are not all
 * the same on purpose.
 *
 * Ten minutes where the payload's fast half is refreshed somewhere else: a
 * listing's player counts and statuses are overlaid in the browser from
 * /servers/live, and its order is `rank`, which is only rewritten every fifteen
 * minutes. History is measurements that only grow. The sitemap is an
 * enumeration nobody reads twice in an hour.
 *
 * A minute where the payload *is* the fast half. `games` and `games/{game}`
 * carry the counters that `counters:refresh` rewrites every minute — a longer
 * window here would freeze them in front of PHP and undo the very split that
 * keeps them current (see GameController::show). Same for a server's own page,
 * whose map, version and wipe time the live layer does not cover.
 *
 * Search is left off deliberately. nginx keys its cache on the URL, so a free
 * text parameter fills it with entries written once and never read — the same
 * reason ListingCache does not cache `q` either.
 */
Route::name('api.')->group(function () {
    Route::get('games', [GameController::class, 'index'])
        ->middleware('cache.public:60')
        ->name('games.index');

    Route::get('games/{game}', [GameController::class, 'show'])
        ->middleware('cache.public:60')
        ->name('games.show');

    /*
     * The player-count chart. A minute, like the games it is assembled from:
     * `steamstats` rewrites those counters every ten minutes and the page is a
     * ranking, which is the kind of thing a visitor reloads to watch move.
     */
    Route::get('charts', [GameChartController::class, 'index'])
        ->middleware('cache.public:60')
        ->name('charts.index');

    // Ten minutes: a game's history grows by one point in that time, and the
    // read behind it is the heaviest in this API.
    Route::get('games/{game}/players', [GameChartController::class, 'history'])
        ->middleware('cache.public:600')
        ->name('games.players');

    // An hour: the newest row moves once a night, when the rollup runs, and
    // every row under it is a month that has already finished.
    Route::get('games/{game}/trend', [GameChartController::class, 'trend'])
        ->middleware('cache.public:3600')
        ->name('games.trend');

    Route::get('games/{game}/servers', [ServerController::class, 'index'])
        ->middleware('cache.public:600')
        ->name('games.servers');

    Route::get('games/{game}/votes', [VoteController::class, 'recent'])
        ->middleware('cache.public:60')
        ->name('games.votes');

    // Verification talks to the submitted address over the network, so this one
    // is throttled far below the read budget.
    Route::post('games/{game}/servers', [ServerSubmissionController::class, 'store'])
        ->middleware('throttle:submissions')
        ->name('games.servers.store');

    // The catalog-wide listing the home page is built from.
    Route::get('servers', [ServerController::class, 'catalog'])
        ->middleware('cache.public:600')
        ->name('servers.index');

    // Must precede servers/{server}, or "live" would be read as a slug.
    Route::get('servers/live', [ServerController::class, 'live'])->name('servers.live');
    Route::get('servers/{server}', [ServerController::class, 'show'])
        ->middleware('cache.public:60')
        ->name('servers.show');

    // Makes us send a packet to somebody else's machine on demand, so it is
    // throttled like the submission form rather than like a read.
    Route::post('servers/{server}/refresh', [ServerController::class, 'refresh'])
        ->middleware('throttle:refreshes')
        ->name('servers.refresh');
    Route::get('servers/{server}/history', [ServerHistoryController::class, 'show'])
        ->middleware('cache.public:600')
        ->name('servers.history');

    Route::get('servers/{server}/vote', [VoteController::class, 'status'])->name('servers.vote.status');
    Route::post('servers/{server}/vote', [VoteController::class, 'store'])
        ->middleware('throttle:votes')
        ->name('servers.vote');
    Route::post('servers/{server}/votes/claim', [VoteController::class, 'claim'])->name('servers.votes.claim');

    Route::get('search', SearchController::class)->name('search');

    // The contact form sends mail to our own support inbox — cheap per
    // request but the mailbox on the other end is one place, so it is
    // throttled below the general read budget.
    Route::post('contact', [ContactController::class, 'store'])
        ->middleware('throttle:contact')
        ->name('contact');

    /*
     * Every server URL there is, for the frontend's sitemap.
     *
     * Apart from the listing above on purpose: that one is capped at ten
     * thousand rows by its own pagination limits, which is correct for
     * something a person browses and useless for an enumeration.
     */
    Route::get('sitemap/servers', [SitemapController::class, 'servers'])
        ->middleware('cache.public:3600')
        ->name('sitemap.servers');

    /*
    |----------------------------------------------------------------------
    | Accounts
    |----------------------------------------------------------------------
    |
    | Signing in and signing up are one act: prove you hold a mailbox or a
    | provider account. There is no registration endpoint because there is no
    | registration step.
    |
    */

    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('email', [EmailCodeController::class, 'store'])
            ->middleware('throttle:auth-codes')
            ->name('email');

        Route::post('email/verify', [EmailCodeController::class, 'verify'])
            ->middleware('throttle:auth-verify')
            ->name('email.verify');

        Route::get('providers', [SessionController::class, 'providers'])->name('providers');

        // Browser navigations, not fetches: the visitor leaves and comes back.
        Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect'])
            ->middleware('throttle:auth-verify')
            ->name('social.redirect');
        Route::get('{provider}/callback', [SocialAuthController::class, 'callback'])
            ->name('social.callback');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', [SessionController::class, 'show'])->name('me');
            Route::post('logout', [SessionController::class, 'destroy'])->name('logout');
        });
    });

    /*
    |----------------------------------------------------------------------
    | Favourites
    |----------------------------------------------------------------------
    |
    | One account's own list, so every one of these is behind the token and
    | none of them is cached anywhere — see FavoriteController.
    |
    */

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('favorites', [FavoriteController::class, 'index'])->name('favorites.index');
        Route::post('servers/{server}/favorite', [FavoriteController::class, 'store'])->name('favorites.store');
        Route::delete('servers/{server}/favorite', [FavoriteController::class, 'destroy'])->name('favorites.destroy');
    });
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
