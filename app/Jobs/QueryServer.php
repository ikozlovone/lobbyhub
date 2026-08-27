<?php

namespace App\Jobs;

use App\Enums\ServerStatus;
use App\Models\Country;
use App\Models\Server;
use App\Models\ServerState;
use App\Services\Geo\GeoResolver;
use App\Services\Monitoring\Contracts\ProvidesServerDetails;
use App\Services\Monitoring\Contracts\ServerQueryDriver;
use App\Services\Monitoring\Exceptions\QueryFailed;
use App\Services\Monitoring\PollingSchedule;
use App\Services\Monitoring\QueryResult;
use App\Services\Monitoring\ServerQueryManager;
use App\Services\Stats\ClickHouseClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One server, queried and recorded.
 *
 * Unique per server while a copy of it is queued, which matters more than it
 * sounds. The dispatcher leases what it queues — it pushes `next_query_at` five
 * minutes out so the next run does not pick the same server again — and that
 * holds only while the queue drains inside five minutes. Once it does not, a
 * server whose job is still waiting comes back into "due" every five minutes
 * and is queued again, and again, for as long as its first job sits there. The
 * queue then fills with copies of a handful of servers while the rest of the
 * catalog is never reached, which reads as "we need more workers" and is not:
 * measured on a stalled queue here, 20,882 jobs turned out to be 314 servers,
 * a median of 67 copies each.
 *
 * `dispatchSync` does not take this lock — the gate lives in PendingDispatch,
 * which only the queued path goes through. That is deliberate and not a
 * loophole: the submission form and the refresh button run inline because
 * somebody is waiting on the answer, and neither should be refused because a
 * scheduled poll of the same server happens to be queued.
 */
class QueryServer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * No queue retries: a failed query is a legitimate result (the server is
     * down) and gets recorded as such. Retrying is the polling cadence's job.
     */
    public int $tries = 1;

    /**
     * When this job was made, which is when the dispatcher decided the server
     * was due. Everything the guard below knows, it knows from comparing this
     * with when the server was actually reached.
     *
     * Nullable, and that is not decoration. A job already in the queue when
     * this field was added was serialized without it, and a typed property with
     * no default is an error to *read* — so the guard would throw on every one
     * of them, and at `tries = 1` a backlog would go straight to failed_jobs
     * instead of being worked. Null is what those jobs have, and it means what
     * it should: nothing is known about when this was queued, so do not skip.
     */
    public ?Carbon $queuedAt = null;

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

        $this->queuedAt = now();
    }

    /** One queued job per server; see the note on the class. */
    public function uniqueId(): string
    {
        return (string) $this->server->getKey();
    }

    /**
     * How long the lock outlives a job that never reports back.
     *
     * Both ways a job normally ends — done, or failed — release it, so this
     * only covers a worker killed mid-query: an OOM, a SIGKILL, a machine
     * rebooted. The cost of getting it wrong is asymmetric in one direction
     * only, which is why it is not longer: too short and copies return during a
     * backlog, too long and one dead process silences one server for that long.
     */
    public function uniqueFor(): int
    {
        return (int) config('monitoring.unique_for', 3600);
    }

    public function handle(ServerQueryManager $manager, GeoResolver $geo, PollingSchedule $schedule): void
    {
        if ($this->wasOvertakenByAnotherQuery()) {
            return;
        }

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
     * Has somebody else already reached this server since this job was made?
     *
     * The second half of the uniqueness guard on the class, and the cheaper
     * half. The lock stops copies being *made*; this stops a copy that exists
     * anyway from spending a worker on a socket somebody else just opened. They
     * exist for two reasons: the lock has an expiry, and there is no lock at all
     * over jobs queued before it shipped.
     *
     * A copy is not free without this. It is a full query and a stat row,
     * indistinguishable from real work right up to the point where you notice
     * the same server in the history sixty times an hour.
     *
     * Comparing against when the job was made rather than against an interval:
     * the question is not "was this recent" but "did this already happen", and
     * only the first has a boundary to get wrong. A server queried after this
     * job was queued has, by definition, had the reading this job was sent for.
     *
     * Two callers are exempt, both because somebody is waiting on the answer.
     * `measured` is the submission form, which queried the address itself before
     * it would agree to store it and is here only to have the reading written
     * down. `forceDetails` is the refresh button, whose own cooldown already
     * decides how often it may knock. Neither is queued, so neither can reach
     * this today — they are named anyway, because a button that silently does
     * nothing is not a failure anyone would go looking for here.
     */
    private function wasOvertakenByAnotherQuery(): bool
    {
        if ($this->measured !== null || $this->forceDetails || $this->queuedAt === null) {
            return false;
        }

        // `last_queried_at` lives on the state row now. A single point read
        // by (game_id, server_id) is one partition, one PK lookup.
        $lastQueried = ServerState::query()
            ->where('game_id', $this->server->game_id)
            ->where('server_id', $this->server->id)
            ->value('last_queried_at');

        return $lastQueried !== null && Carbon::parse($lastQueried)->greaterThan($this->queuedAt);
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

        // Asked before anything is filled in: `game_port` might be about to
        // change on the state row, address() reads it, and a moment later
        // this comparison would be against a different string than the one
        // the row was named with.
        $unnamed = $this->isStillNamedAfterItsAddress();

        $state = [
            'status' => ServerStatus::Online->value,
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
                $state[$column] = $value;
            }
        }

        // Pick the tier off the numbers we just got — the state row has not
        // been updated yet, and the model no longer carries player counts.
        $state['next_query_at'] = $now->copy()->addSeconds(
            $schedule->intervalFor($result->playersOnline, $this->server->isPromoted()),
        );
        $state['updated_at'] = $now;

        $this->writeState($state);

        // Cold-side writes only when they actually changed: `ip_address` and
        // location move rarely, and the sweep does not touch `servers` on the
        // hot path, so this is the sole place they can change now.
        $cold = [];

        if ($result->ipAddress !== null && $result->ipAddress !== $this->server->ip_address) {
            $cold['ip_address'] = $result->ipAddress;
            $cold += $this->resolveLocation($result->ipAddress, $geo);
        }

        $cold += $this->adoptReportedName($result, $unnamed);

        if ($cold !== []) {
            $this->server->forceFill($cold)->save();
        }

        $this->recordClickHouse($now, $result);
    }

    /**
     * Give a server its real name the first time it answers.
     *
     * A bulk import has nothing to call a row but the address that was pasted,
     * so it writes that as the name — and without this the server would keep
     * being called `45.152.161.10:28015` in every listing long after it told us
     * it was called something else.
     *
     * Guarded by "the name is still exactly the address", which is true only of
     * a row nobody has named. That matters twice over: an owner who edited the
     * name in the admin keeps it, and the submission form — which names the
     * server from the same MOTD before dispatching this job synchronously — is
     * not re-slugged into a duplicate of the slug it just minted.
     *
     * Renaming rewrites the slug, which is a public URL, and doing that would
     * normally be unacceptable. It is safe here precisely because it has never
     * been public: until this query lands the row is `unknown`, and every
     * listing filters those out (see verified-only reads in ServerListing).
     *
     * @return array<string, mixed>  fields for the caller to merge into a
     *                               single cold-side write; empty when nothing
     *                               is being adopted.
     */
    private function adoptReportedName(QueryResult $result, bool $unnamed): array
    {
        $reported = trim((string) ($result->motd ?? ''));

        if (! $unnamed || $reported === '') {
            return [];
        }

        $name = mb_substr($reported, 0, 255);
        $slug = Server::slugFor($name, $this->server->host, $this->server->port);

        return ['name' => $name, 'slug' => $slug];
    }

    /**
     * Both spellings, because an IPv6 host is bracketed by the parser that
     * named the row and bare in address().
     */
    private function isStillNamedAfterItsAddress(): bool
    {
        $host = $this->server->host;
        $bracketed = str_contains($host, ':') ? "[{$host}]" : $host;

        return in_array($this->server->name, [
            $this->server->address(),
            $bracketed.':'.$this->server->port,
        ], strict: true);
    }

    private function recordOffline(PollingSchedule $schedule): void
    {
        $now = now();

        // Read the current counter off the state row rather than the model
        // (which no longer carries it), so we increment the right value even
        // if the server was just queried by somebody else.
        $currentFailures = (int) ServerState::query()
            ->where('game_id', $this->server->game_id)
            ->where('server_id', $this->server->id)
            ->value('failed_queries_count');

        $failures = min($currentFailures + 1, 65535);

        $this->writeState([
            'status' => ServerStatus::Offline->value,
            'players_online' => 0,
            'last_queried_at' => $now,
            // Every failure, not only the first of a run: this answers "when was
            // it last down", and during an outage that is now.
            'last_offline_at' => $now,
            'failed_queries_count' => $failures,
            'next_query_at' => $now->copy()->addSeconds($schedule->backoffFor($failures)),
            'updated_at' => $now,
        ]);
    }

    /**
     * Upsert the state row for the current server.
     *
     * `ON CONFLICT (game_id, server_id) DO UPDATE` is safe: the composite PK
     * matches the partition's primary key, and this handler always has both
     * keys. `game_id` in the INSERT also gives Postgres the partition to
     * route the write to without walking the tuple router.
     *
     * @param  array<string, mixed>  $columns
     */
    private function writeState(array $columns): void
    {
        $row = [
            'server_id' => $this->server->id,
            'game_id' => $this->server->game_id,
        ] + $columns;

        // `created_at` on insert only — a state row exists for the lifetime
        // of its server, so this is normally the discovery moment.
        $row['created_at'] ??= $row['updated_at'] ?? now();

        ServerState::query()->upsert(
            [$row],
            uniqueBy: ['game_id', 'server_id'],
            update: array_keys($columns),
        );
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

    /**
     * Mirror a fresh online sample into ClickHouse so the frontend chart
     * updates without waiting for the next sweep tick.
     *
     * Only fires for the two callers that are interactive — the refresh
     * button (`forceDetails`) and the submission form (`measured`). The
     * scheduled polling path (both flags false) writes state and stats;
     * ClickHouse from that side is a2s-benchmark's job, and doing it twice
     * from PHP would leave two points in the same 10-minute bucket for
     * every server on every sweep.
     *
     * ts is truncated to a ten-minute boundary — same rule the sweeper
     * uses, so a refresh at 14:07 lands in the same bucket the 14:00 tick
     * did. Downsampling in ServerHistory averages the two, which is what
     * we want: a manual refresh nudges the point rather than adding a
     * stray one next to it.
     *
     * Fail-open: a ClickHouse-side error is logged and swallowed so a
     * broken analytics box does not fail the user's refresh.
     */
    private function recordClickHouse(Carbon $at, QueryResult $result): void
    {
        if (! $this->measured && ! $this->forceDetails) {
            return;
        }

        // Truncate down to the nearest ten-minute mark in UTC — same rule
        // the Go sweeper uses, so both writers put a 14:07 refresh into
        // the 14:00 bucket.
        $bucket = $at->copy()->utc()->second(0)->minute(
            (int) (floor((int) $at->utc()->format('i') / 10) * 10),
        );

        try {
            app(ClickHouseClient::class)->execute(
                'INSERT INTO server_players_raw (ts, game_id, server_id, players_online)
                 VALUES ({ts:DateTime}, {game_id:UInt32}, {server_id:UInt64}, {players:UInt16})',
                [
                    'ts' => $bucket->format('Y-m-d H:i:s'),
                    'game_id' => (int) $this->server->game_id,
                    'server_id' => (int) $this->server->id,
                    'players' => max(0, (int) $result->playersOnline),
                ],
            );
        } catch (Throwable $e) {
            Log::warning('QueryServer ClickHouse write failed', [
                'server_id' => $this->server->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
