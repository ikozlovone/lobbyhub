<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Steam Web API
    |--------------------------------------------------------------------------
    |
    | Used for server discovery: IGameServersService/GetServerList returns every
    | registered server for a Steam app id, with its metadata, in one request.
    | The legacy UDP master server it replaced no longer resolves.
    |
    | Free key, tied to a Steam account: https://steamcommunity.com/dev/apikey
    |
    */

    'steam' => [
        'key' => env('STEAM_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sign-in providers
    |--------------------------------------------------------------------------
    |
    | Steam needs nothing beyond the Web API key above — OpenID 2.0 has no
    | client secret. Discord and Google are ordinary OAuth 2 clients, and each
    | needs its redirect URI registered as:
    |
    |   {APP_URL}/api/auth/discord/callback
    |   {APP_URL}/api/auth/google/callback
    |
    | A provider without credentials is simply not offered: the dialog asks the
    | API which buttons to draw.
    |
    | Discord: https://discord.com/developers/applications → OAuth2
    | Google:  https://console.cloud.google.com/apis/credentials → OAuth client ID
    |
    */

    'discord' => [
        'client_id' => env('DISCORD_CLIENT_ID'),
        'client_secret' => env('DISCORD_CLIENT_SECRET'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend
    |--------------------------------------------------------------------------
    |
    | Where a provider round trip lands. Defaults to the first allowed CORS
    | origin, so a single FRONTEND_ORIGINS value configures both.
    |
    */

    'frontend' => [
        'url' => env('FRONTEND_URL') ?: explode(',', (string) env('FRONTEND_ORIGINS', 'http://localhost:3000'))[0],

        /*
         * Where to tell the frontend that a cached page is out of date, and the
         * shared secret that proves it is us asking. Both must be set, or
         * revalidation is skipped and freshness falls back to the cache window
         * — an endpoint anyone may call is a way to make the site rebuild every
         * page it has, on repeat.
         */
        'revalidate_url' => env('FRONTEND_REVALIDATE_URL'),
        'revalidate_secret' => env('FRONTEND_REVALIDATE_SECRET'),
    ],

];
