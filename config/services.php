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

        /*
         * More than one key, comma-separated in the same variable.
         *
         * A key is rated per day, and the sweep's cost is not small: twelve of
         * the catalog's forty-five games hold more servers than one response
         * can carry, and reaching all of them costs eighteen requests for
         * Valheim and sixty-eight for Counter-Strike 2. Requests are dealt
         * round-robin across whatever is listed here, so a second key is a
         * second day's allowance and nothing else has to change.
         *
         * One key stays one key: a value with no comma in it is a list of one.
         */
        'keys' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('STEAM_API_KEY', '')),
        ))),
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

    /*
    |--------------------------------------------------------------------------
    | Mail routing
    |--------------------------------------------------------------------------
    |
    | Where the contact form's messages land. Kept out of the controller so a
    | fork does not silently mail its own operators — set CONTACT_TO in .env,
    | otherwise MAIL_FROM_ADDRESS is used as a fallback that at least reaches
    | somebody on the same deployment.
    |
    */

    'mail' => [
        'contact_to' => env('CONTACT_TO'),
    ],

    /*
    |--------------------------------------------------------------------------
    | ClickHouse
    |--------------------------------------------------------------------------
    |
    | Storage for per-server player-count history. Written to by a2s-benchmark
    | (native protocol on port 9000), read from Laravel over HTTP on 8123 —
    | the same rules Postgres has for its own port split. The Go sweeper reads
    | CH_PORT for its 9000 dial; Laravel needs CH_PORT_HTTP for 8123, hence
    | two variables sharing the same server.
    |
    | Empty CH_HOST leaves everything nulled — the reader in ServerHistory
    | catches the missing config and returns an empty graph, so a Laravel
    | install without ClickHouse still boots.
    |
    */

    'clickhouse' => [
        'host' => env('CH_HOST'),
        'port_http' => (int) env('CH_PORT_HTTP', 8123),
        'database' => env('CH_DATABASE', 'lobbyhub_stats'),
        'username' => env('CH_USERNAME', 'default'),
        'password' => env('CH_PASSWORD', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | The cache in front of PHP
    |--------------------------------------------------------------------------
    |
    | Where nginx keeps the answers this API told it it could keep — the
    | `fastcgi_cache_path` in conf.d/lobbyhub.conf, and the `levels=` it was
    | declared with. Both are needed to work out the file one URL is stored
    | in; see SharedCache for how.
    |
    | It is only ever used to drop an entry the application knows is wrong,
    | which today is one thing: the server somebody just pressed Refresh on.
    | Nothing reads it, nothing writes it, and an unset path means there is no
    | shared cache in front of this install — local development, the test
    | suite — and every drop is a no-op.
    |
    */

    'nginx' => [
        'cache_path' => env('NGINX_CACHE_PATH'),
        'cache_levels' => env('NGINX_CACHE_LEVELS', '1:2'),
    ],

];
