<?php

use App\Http\Middleware\CachePublicReads;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel does not throttle the api group on its own; the limit itself
        // is defined as the `api` limiter in AppServiceProvider.
        $middleware->api(prepend: [
            ThrottleRequests::class.':api',
        ]);

        // Named rather than applied to the group: it belongs on the public
        // reads one at a time, and the group also holds the routes it must
        // never touch. See the class.
        $middleware->alias([
            'cache.public' => CachePublicReads::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
