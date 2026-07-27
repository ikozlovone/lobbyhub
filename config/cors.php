<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /**
     * The Next.js frontend runs on its own origin (Vercel), so it has to be
     * listed explicitly. Keep this a whitelist: '*' would let any site read
     * authenticated responses once the owner panel exists.
     */
    'allowed_origins' => array_filter(explode(',', (string) env('FRONTEND_ORIGINS', 'http://localhost:3000'))),

    /** Vercel preview deployments get a fresh subdomain per branch. */
    'allowed_origins_patterns' => array_filter([
        env('FRONTEND_PREVIEW_PATTERN'),
    ]),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
