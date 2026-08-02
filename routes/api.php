<?php

use App\Http\Controllers\Api\Auth\EmailCodeController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\Auth\SocialAuthController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\ServerHistoryController;
use App\Http\Controllers\Api\ServerSubmissionController;
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

Route::name('api.')->group(function () {
    Route::get('games', [GameController::class, 'index'])->name('games.index');
    Route::get('games/{game}', [GameController::class, 'show'])->name('games.show');
    Route::get('games/{game}/servers', [ServerController::class, 'index'])->name('games.servers');
    Route::get('games/{game}/votes', [VoteController::class, 'recent'])->name('games.votes');

    // Verification talks to the submitted address over the network, so this one
    // is throttled far below the read budget.
    Route::post('games/{game}/servers', [ServerSubmissionController::class, 'store'])
        ->middleware('throttle:submissions')
        ->name('games.servers.store');

    // The catalog-wide listing the home page is built from.
    Route::get('servers', [ServerController::class, 'catalog'])->name('servers.index');

    // Must precede servers/{server}, or "live" would be read as a slug.
    Route::get('servers/live', [ServerController::class, 'live'])->name('servers.live');
    Route::get('servers/{server}', [ServerController::class, 'show'])->name('servers.show');

    // Makes us send a packet to somebody else's machine on demand, so it is
    // throttled like the submission form rather than like a read.
    Route::post('servers/{server}/refresh', [ServerController::class, 'refresh'])
        ->middleware('throttle:refreshes')
        ->name('servers.refresh');
    Route::get('servers/{server}/history', [ServerHistoryController::class, 'show'])->name('servers.history');

    Route::get('servers/{server}/vote', [VoteController::class, 'status'])->name('servers.vote.status');
    Route::post('servers/{server}/vote', [VoteController::class, 'store'])
        ->middleware('throttle:votes')
        ->name('servers.vote');
    Route::post('servers/{server}/votes/claim', [VoteController::class, 'claim'])->name('servers.votes.claim');

    Route::get('search', SearchController::class)->name('search');

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
