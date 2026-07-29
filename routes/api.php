<?php

use App\Http\Controllers\Api\Auth\EmailCodeController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\Auth\SocialAuthController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\ServerHistoryController;
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
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
