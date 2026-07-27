<?php

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Country;
use App\Models\Server;
use App\Models\ServerStat;
use App\Services\Geo\GeoResolver;
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

    public function __construct(public Server $server)
    {
        $this->onQueue(config('monitoring.queue'));
    }

    public function handle(ServerQueryManager $manager, GeoResolver $geo, PollingSchedule $schedule): void
    {
        try {
            $result = $manager->for($this->server)->query($this->server);
        } catch (QueryFailed) {
            $this->recordOffline($schedule);

            return;
        }

        $this->recordOnline($result, $geo, $schedule);
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
            'wiped_at' => $result->wipedAt,
            'players_queued' => $result->playersQueued,
        ] as $column => $value) {
            if ($value !== null) {
                $attributes[$column] = $value;
            }
        }

        if ($result->ipAddress !== null) {
            $attributes['ip_address'] = $result->ipAddress;
            $attributes += $this->resolveCountry($result->ipAddress, $geo);
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
            'failed_queries_count' => $failures,
            'next_query_at' => $now->copy()->addSeconds($schedule->backoffFor($failures)),
        ])->save();

        $this->recordSample($now, false);
    }

    /**
     * Only look up servers that have no country yet — the IP rarely moves, and
     * this keeps the reader off the hot path for the whole catalog.
     *
     * @return array<string, int>
     */
    private function resolveCountry(string $ip, GeoResolver $geo): array
    {
        if ($this->server->country_id !== null) {
            return [];
        }

        $code = $geo->countryCode($ip);

        if ($code === null) {
            return [];
        }

        $countryId = Country::query()->where('code', $code)->value('id');

        return $countryId === null ? [] : ['country_id' => $countryId];
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
