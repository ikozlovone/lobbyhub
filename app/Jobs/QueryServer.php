<?php

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Country;
use App\Models\Server;
use App\Models\ServerStat;
use App\Services\Geo\GeoResolver;
use App\Services\Monitoring\Contracts\ProvidesServerDetails;
use App\Services\Monitoring\Contracts\ServerQueryDriver;
use App\Services\Monitoring\Exceptions\QueryFailed;
use App\Services\Monitoring\PollingSchedule;
use App\Services\Monitoring\QueryResult;
use App\Services\Monitoring\ServerQueryManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class QueryServer implements ShouldQueue
{
    use Queueable;

    /**
     * No queue retries: a failed query is a legitimate result (the server is
     * down) and gets recorded as such. Retrying is the polling cadence's job.
     */
    public int $tries = 1;

    /**
     * @param  QueryResult|null  $measured  An answer already in hand, from a
     *                                      caller that had to query the address
     *                                      before it could decide to save it at
     *                                      all — the submission form. Recording
     *                                      is this job's business either way, so
     *                                      the result is passed in rather than
     *                                      the recording being written twice.
     * @param  bool  $forceDetails  Ask for the slow-moving facts too, whatever
     *                              the daily cadence says. Set by the refresh
     *                              button and by nothing on the schedule — see
     *                              refreshDetails().
     */
    public function __construct(
        public Server $server,
        public ?QueryResult $measured = null,
        public bool $forceDetails = false,
    ) {
        $this->onQueue(config('monitoring.queue'));
    }

    public function handle(ServerQueryManager $manager, GeoResolver $geo, PollingSchedule $schedule): void
    {
        $driver = $manager->for($this->server);

        try {
            $result = $this->measured ?? $driver->query($this->server);
        } catch (QueryFailed) {
            $this->recordOffline($schedule);

            return;
        }

        $this->recordOnline($result, $geo, $schedule);
        $this->refreshDetails($driver);
    }

    /**
     * Map, description, images and tuning values barely change, and asking for
     * them costs a second exchange — so they are refreshed on their own slow
     * cadence rather than on every poll. A failure here is not downtime: the
     * server already answered.
     *
     * Except when somebody asked. The refresh button sits in the panel these
     * fields fill, and a button that leaves most of the block it lives in
     * untouched for another day is not a refresh — it is a button that lies.
     * The cost is bounded by what guards that endpoint: one re-query a minute
     * per server, six a minute per address asking.
     */
    private function refreshDetails(ServerQueryDriver $driver): void
    {
        if (! $driver instanceof ProvidesServerDetails) {
            return;
        }

        if ($this->forceDetails) {
            $this->readDetails($driver);

            return;
        }

        $interval = (int) config('monitoring.details_interval', 86400);
        $syncedAt = $this->server->details_synced_at;

        if ($syncedAt !== null && $syncedAt->addSeconds($interval)->isFuture()) {
            return;
        }

        $this->readDetails($driver);
    }

    /** The second exchange, and the row it writes. */
    private function readDetails(ProvidesServerDetails&ServerQueryDriver $driver): void
    {
        try {
            $details = $driver->details($this->server);
        } catch (QueryFailed) {
            return;
        }

        $this->server->forceFill([
            'details' => $details === [] ? null : $details,
            'details_synced_at' => now(),
        ])->save();
    }

    private function recordOnline(QueryResult $result, GeoResolver $geo, PollingSchedule $schedule): void
    {
        $now = now();

        $attributes = [
            'status' => ServerStatus::Online,
            'players_online' => $result->playersOnline,
            'players_max' => $result->playersMax,
            'reported_version' => $result->version,
            'motd' => $result->motd,
            'last_queried_at' => $now,
            'last_online_at' => $now,
            'failed_queries_count' => 0,
        ];

        // Fields a protocol cannot report stay untouched rather than being
        // overwritten with nulls — Minecraft has no map, Rust has no MOTD.
        foreach ([
            'map' => $result->map,
            'game_port' => $result->gamePort,
            'steam_id' => $result->steamId,
            'wiped_at' => $result->wipedAt,
            'players_queued' => $result->playersQueued,
            'bots' => $result->bots,
            'vac_enabled' => $result->vacEnabled,
        ] as $column => $value) {
            if ($value !== null) {
                $attributes[$column] = $value;
            }
        }

        if ($result->ipAddress !== null) {
            $attributes['ip_address'] = $result->ipAddress;
            $attributes += $this->resolveLocation($result->ipAddress, $geo);
        }

        // Fill first, then pick the tier: it reads the player count we just got.
        $this->server->forceFill($attributes);
        $this->server->next_query_at = $now->copy()->addSeconds($schedule->intervalFor($this->server));
        $this->server->save();

        $this->recordSample($now, true, $result);
    }

    private function recordOffline(PollingSchedule $schedule): void
    {
        $now = now();
        $failures = min($this->server->failed_queries_count + 1, 65535);

        $this->server->forceFill([
            'status' => ServerStatus::Offline,
            'players_online' => 0,
            'last_queried_at' => $now,
            // Every failure, not only the first of a run: this answers "when was
            // it last down", and during an outage that is now.
            'last_offline_at' => $now,
            'failed_queries_count' => $failures,
            'next_query_at' => $now->copy()->addSeconds($schedule->backoffFor($failures)),
        ])->save();

        $this->recordSample($now, false);
    }

    /**
     * Only look up servers that are missing a location — the IP rarely moves,
     * and this keeps the reader off the hot path for the whole catalog.
     *
     * A server placed with the Country database has no city; once the City
     * database is in place it gets picked up on the next lookup, because the
     * gate below is "no country OR no city".
     *
     * @return array<string, mixed>
     */
    private function resolveLocation(string $ip, GeoResolver $geo): array
    {
        if ($this->server->country_id !== null && $this->server->city !== null) {
            return [];
        }

        $location = $geo->locate($ip);

        if ($location === null || $location->isEmpty()) {
            return [];
        }

        $attributes = [];

        if ($this->server->country_id === null && $location->countryCode !== null) {
            $countryId = Country::query()->where('code', $location->countryCode)->value('id');

            if ($countryId !== null) {
                $attributes['country_id'] = $countryId;
            }
        }

        if ($location->city !== null) {
            $attributes['city'] = $location->city;
        }

        return $attributes;
    }

    private function recordSample(Carbon $at, bool $online, ?QueryResult $result = null): void
    {
        // upsert, not insert: two dispatches landing in the same second must not
        // collide on the (server_id, recorded_at) primary key.
        ServerStat::upsert([[
            'server_id' => $this->server->id,
            'recorded_at' => $at,
            'is_online' => $online,
            'players_online' => $result?->playersOnline ?? 0,
            'players_max' => $result?->playersMax ?? 0,
            'latency_ms' => $result?->latencyMs,
        ]], ['server_id', 'recorded_at'], ['is_online', 'players_online', 'players_max', 'latency_ms']);
    }
}
