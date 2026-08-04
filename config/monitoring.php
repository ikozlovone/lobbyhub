<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Polling cadence
    |--------------------------------------------------------------------------
    |
    | A healthy server is re-queried every `interval` seconds. Every consecutive
    | failure doubles that wait, capped at `max_interval`, so dead listings stop
    | eating the queue without being dropped from the catalog.
    |
    */

    'interval' => (int) env('MONITORING_INTERVAL', 300),

    'max_interval' => (int) env('MONITORING_MAX_INTERVAL', 21600),

    /*
    |--------------------------------------------------------------------------
    | Cadence tiers
    |--------------------------------------------------------------------------
    |
    | Most of any catalog sits empty, and querying a dead-quiet server as often
    | as a full one is pure waste — it buys nothing and multiplies both the queue
    | and the history table. Tiers are matched top-down on the player count from
    | the query that just finished; the first match wins.
    |
    | Nothing is polled more often than every five minutes. The busiest tier used
    | to sit at two, which bought precision nobody reads — the live layer in the
    | browser refreshes on its own clock, and a player count from four minutes ago
    | reads the same as one from ninety seconds ago. What it cost was real: those
    | are packets aimed at machines that are not ours, at two and a half times
    | this rate, and a history table growing at the same multiple.
    |
    */

    'tiers' => [
        ['min_players' => 100, 'interval' => 300],
        ['min_players' => 10, 'interval' => 600],
        ['min_players' => 1, 'interval' => 1800],
        ['min_players' => 0, 'interval' => 3600],
    ],

    /**
     * Paid placements stay fresh no matter how quiet they are — but not faster
     * than the floor above, or the promise would be "we knock on your server
     * twice as often as anyone else's", which is not what was sold.
     */
    'promoted_interval' => (int) env('MONITORING_PROMOTED_INTERVAL', 300),

    /**
     * Servers dispatched per run of `servers:query`.
     *
     * This is a hard ceiling on the whole monitor, and the arithmetic is worth
     * doing before changing anything else: the dispatcher runs once a minute, so
     * the most it can ever ask for is `batch_size * 60` queries an hour. No
     * number of workers gets past that.
     *
     * `monitoring:status` prints the demand as "queries expected". Keep this
     * above that figure divided by 60, with room to spare — a batch is not
     * always full, because the per-host cap below holds servers back.
     *
     * 500 was sized for a few thousand servers and quietly became the binding
     * constraint at twenty-odd thousand: 30,000 an hour against 92,000 asked
     * for, so a third of the catalog on the right cadence and the rest drifting.
     */
    'batch_size' => (int) env('MONITORING_BATCH_SIZE', 2000),

    /**
     * How long one server's queued query blocks another being queued for it.
     *
     * The lock is normally released the moment the job finishes, so this only
     * matters when a worker dies holding one. See QueryServer.
     */
    'unique_for' => (int) env('MONITORING_UNIQUE_FOR', 3600),

    /**
     * How long a server seen in Steam's own list is left alone by the poller.
     *
     * Two windows, because there are two passes and one number cannot serve
     * both. A server with players on it is read every five minutes by the
     * occupied pass, and its disappearance is worth noticing quickly — that is
     * a busy server going down. An empty one is only in the half-hourly full
     * pass, sits on the hour-long tier, and nobody is waiting on the news.
     *
     * Each is about twice the interval of the pass that refreshes it, so a
     * cycle running long does not dump the catalog back onto the queue.
     *
     * Getting this wrong is not subtle: these were one number at 900 seconds
     * while the full pass ran every 1800, so every empty server in the catalog
     * — a hundred thousand of them — was legally uncovered for half of every
     * cycle and fell to the poller, which could reach three thousand an hour.
     *
     * The cost of raising either is the same: absence from Steam is what tells
     * us a server is gone, and only the poller this gates can confirm it.
     */
    'steam_trust_populated' => (int) env('MONITORING_STEAM_TRUST_POPULATED', 900),

    'steam_trust_quiet' => (int) env('MONITORING_STEAM_TRUST_QUIET', 3600),

    /**
     * The queue the Steam sweeps go to, kept apart from the per-server queries.
     *
     * Not tidiness — the failure it prevents took the monitor down. Both job
     * kinds shared one queue, the database driver takes jobs in id order, and
     * the dispatcher can add two thousand queries a minute. Sweep jobs landed
     * behind a hundred thousand of them and were thirty hours from running;
     * without sweeps every server fell to the poller, which filled the queue
     * further. A worker reading `steam,monitoring` takes a sweep first however
     * deep the other queue is — see deploy/systemd/lobbyhub-worker@.service.
     */
    'steam_queue' => env('MONITORING_STEAM_QUEUE', 'steam'),

    /**
     * How deep the query queue may get before the dispatcher stops adding.
     *
     * A backlog this size is not work waiting to be done, it is a statement
     * that the workers cannot keep up — and every further batch makes the
     * queue longer without a single extra server being reached. Left
     * unbounded it grew to a hundred thousand, at which point anything queued
     * behind it, sweeps included, was effectively lost.
     *
     * Skipping a run costs nothing: the servers stay due and are picked up as
     * soon as there is room.
     */
    'max_queue_depth' => (int) env('MONITORING_MAX_QUEUE_DEPTH', 10000),

    /**
     * How many rows in one Steam response count as a truncated answer.
     *
     * The cap is not exact — the same saturated question answered 10 000,
     * 9 999, 9 996 and 9 971 — so a sweep that tests for the ceiling itself
     * reads a cut-off bucket as a finished one and stops looking. Ninety
     * percent, and generous on purpose: subdividing a bucket that was not full
     * costs a few requests, failing to subdivide one that was loses servers.
     */
    'steam_saturated_at' => (int) env('MONITORING_STEAM_SATURATED_AT', 9000),

    /**
     * One provider often holds hundreds of servers behind a single IP. Querying
     * them all in one batch looks like a port scan, so a batch takes at most
     * this many per host and the rest wait for the next run.
     */
    'max_per_host' => (int) env('MONITORING_MAX_PER_HOST', 10),

    /**
     * How often to re-fetch the slow-moving details a server publishes about
     * itself (map, description, images). A day is plenty — these change on wipe.
     */
    'details_interval' => (int) env('MONITORING_DETAILS_INTERVAL', 86400),

    /** Socket connect + read timeout, in seconds. */
    'timeout' => (float) env('MONITORING_TIMEOUT', 5),

    'queue' => env('MONITORING_QUEUE', 'monitoring'),

    'minecraft' => [
        /** Protocol version sent in the handshake; 767 = 1.21. Servers answer status regardless. */
        'protocol_version' => (int) env('MONITORING_MC_PROTOCOL', 767),

        /** Resolve _minecraft._tcp SRV records, the way the vanilla client does. */
        'resolve_srv' => (bool) env('MONITORING_MC_SRV', true),
    ],

    'source' => [
        /** A2S servers answer with a challenge that must be echoed back; some do it twice. */
        'challenge_retries' => (int) env('MONITORING_A2S_CHALLENGE_RETRIES', 2),

        /**
         * Challenges are reusable across sockets and minutes, so caching one per
         * address saves a round trip per query. A stale entry costs nothing —
         * the server simply issues a new challenge and the retry loop uses it.
         */
        'challenge_ttl' => (int) env('MONITORING_A2S_CHALLENGE_TTL', 3600),
    ],

    'geoip' => [
        /**
         * MaxMind GeoLite2 databases, tried in order. City also carries country
         * data, so it alone is enough; Country is the fallback when only that
         * one has been downloaded. Without either, geo resolution no-ops and
         * monitoring carries on.
         *
         * Free licence key: https://www.maxmind.com/en/accounts/current/geoip/downloads
         */
        'databases' => array_values(array_filter([
            env('GEOLITE2_CITY_DB', storage_path('app/geoip/GeoLite2-City.mmdb')),
            env('GEOLITE2_COUNTRY_DB', storage_path('app/geoip/GeoLite2-Country.mmdb')),
        ])),
    ],

];
