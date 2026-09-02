<?php

namespace App\Services\Discovery;

use App\Enums\ServerStatus;
use App\Models\Country;
use App\Models\Game;
use App\Models\Server;
use App\Services\Geo\GeoResolver;
use App\Services\Monitoring\PollingSchedule;
use App\Services\Monitoring\ServerStatePartitionManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes an EOS sweep into the catalog, one deployment at a time.
 *
 * Shaped after SteamCatalogSync — same batched UPDATE-from-VALUES statements,
 * same address-map-then-write pattern, same "cold row only for the newly
 * discovered, state row every pass" split. It is written separately rather
 * than sharing a base class because EOS carries a smaller set of facts (no
 * `steam_id`, `bots`, `vac_enabled`, `game_port`, `wiped_at`, or
 * `steam_seen_at`) and the presence of those columns in the Steam path is
 * what most of that class is about. Two hundred and fifty lines of shared
 * infrastructure to save fifty of divergent logic is not a saving.
 *
 * What EOS gives that A2S never could: a live snapshot of a game with no
 * Steam-side server registration. ASA is the case that opened this door —
 * the game does not answer A2S on any port, and this class is the reason a
 * server that says "online, 45/70" on Wildcard's own browser now says the
 * same on the catalog rather than "offline, 0".
 *
 * One port per session — ASA has no separate query port, and the `port` here
 * is what a joining client dials. Existing rows imported from gamemonitoring
 * (which also carries just one port) match cleanly against this address key.
 */
class EosCatalogSync
{
    /** Rows per statement. Matches the other bulk writers. */
    private const CHUNK = 500;

    private Game $game;

    private Carbon $now;

    /** @var array<string, array{0: int, 1: ?int, 2: bool}>  ip:port → id, next_query_at, deleted */
    private array $byAddress = [];

    /** @var Collection<string, int> */
    private Collection $countries;

    /** @var list<array<string, mixed>> */
    private array $stateUpdates = [];

    /** @var list<array<string, mixed>> */
    private array $inserts = [];

    /**
     * The DiscoveredEosServer that produced each pending insert, kept parallel
     * to `$inserts` by index so state rows can be built once ids are minted.
     *
     * @var list<DiscoveredEosServer>
     */
    private array $pendingStates = [];

    /** @var list<array<string, mixed>> */
    private array $stateInserts = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** Milliseconds, by phase. @var array<string, float> */
    private array $spent = [];

    /** Ids written this run, so the same row is not touched twice. @var array<int, true> */
    private array $touched = [];

    public function __construct(
        private readonly PollingSchedule $schedule,
        private readonly GeoResolver $geo,
        private readonly ServerStatePartitionManager $partitions,
    ) {}

    public function run(Game $game, EosServerSweep $sweep, ?int $maxPages = null): EosSyncReport
    {
        $startedAt = hrtime(true);

        $this->partitions->ensureFor($game);

        $this->game = $game;
        $this->now = now();
        $this->stateUpdates = $this->inserts = $this->pendingStates = $this->stateInserts = [];
        $this->touched = [];
        $this->counts = ['updated' => 0, 'created' => 0, 'skipped' => 0];
        $this->spent = ['db' => 0.0, 'existing' => 0.0];

        $loading = hrtime(true);
        $this->byAddress = $this->existing($game);
        $this->countries = Country::query()->pluck('id', 'code');
        $this->spent['existing'] = (hrtime(true) - $loading) / 1e6;

        /*
         * The catalog's addresses fed down as a filter so the sweep can throw
         * away rows we would not keep before building anything from them.
         *
         * The default is a closed catalog — new EOS servers land through the
         * gamemonitoring import or the admin form, same rule Steam sync
         * follows. `monitoring.eos_create_new_servers` opens the door for a
         * one-off backfill, when the whole population of a title is being
         * pulled in for the first time and there is no other source for it.
         */
        $only = config('monitoring.eos_create_new_servers', false) ? null : $this->byAddress;

        $result = $sweep->stream($game, fn (DiscoveredEosServer $found) => $this->write($found), $maxPages, $only);

        $this->flushStateUpdates();
        $this->flushInserts();
        $this->flushStateInserts();

        $totalMs = (hrtime(true) - $startedAt) / 1e6;

        $rowsMs = max(0.0, $totalMs - $result->httpMs - $this->spent['db'] - $this->spent['existing']);

        return new EosSyncReport(
            found: $result->found,
            distinct: $result->distinct,
            updated: $this->counts['updated'],
            created: $this->counts['created'],
            pages: $result->pages,
            skipped: $this->counts['skipped'] + $result->skipped,
            totalMs: $totalMs,
            httpMs: $result->httpMs,
            rowsMs: $rowsMs,
            dbMs: $this->spent['db'],
            existingMs: $this->spent['existing'],
        );
    }

    private function write(DiscoveredEosServer $found): void
    {
        $key = $found->ip.':'.$found->port;
        $existing = $this->byAddress[$key] ?? null;

        // Soft-deleted rows stay deleted — sync would otherwise reinsert them
        // every pass, and the delete decision belongs to a person, not this.
        if ($existing !== null && $existing[2]) {
            return;
        }

        if ($existing === null) {
            if (! config('monitoring.eos_create_new_servers', false)) {
                $this->counts['skipped']++;

                return;
            }

            $this->inserts[] = $this->newRow($found);
            $this->pendingStates[] = $found;
            $this->counts['created']++;
        } else {
            [$id, $nextQueryAt] = $existing;

            if (isset($this->touched[$id])) {
                return;
            }

            $this->touched[$id] = true;

            $due = $nextQueryAt === null || $nextQueryAt <= $this->now->getTimestamp();

            $this->stateUpdates[] = $this->snapshot($found, $due, $id, $nextQueryAt);
            $this->counts['updated']++;
        }

        if (count($this->stateUpdates) >= self::CHUNK) {
            $this->flushStateUpdates();
        }

        if (count($this->inserts) >= self::CHUNK) {
            $this->flushInserts();
        }
    }

    /**
     * Every row this game already has, keyed by `ip:port` — the only address
     * an EOS session carries.
     *
     * One key per row rather than three (see SteamCatalogSync for why that
     * needs three): EOS gives one port only, so `ADDRESS_s:GAMEPORT_l` on the
     * wire compares directly against `ip_address:port` in the row. A server
     * somebody submitted by domain still matches, because `ip_address` was
     * resolved from that domain by the same process that keyed this map.
     *
     * @return array<string, array{0: int, 1: ?int, 2: bool}>
     */
    private function existing(Game $game): array
    {
        $keyed = [];

        DB::table('servers')
            ->where('game_id', $game->id)
            ->select(['id', 'host', 'port', 'ip_address', 'next_query_at', 'deleted_at'])
            ->chunkById(2000, function (Collection $rows) use (&$keyed) {
                foreach ($rows as $row) {
                    $entry = [
                        (int) $row->id,
                        $row->next_query_at === null ? null : Carbon::parse($row->next_query_at)->getTimestamp(),
                        $row->deleted_at !== null,
                    ];

                    $keyed[$row->host.':'.$row->port] ??= $entry;

                    if ($row->ip_address !== null) {
                        $keyed[$row->ip_address.':'.$row->port] ??= $entry;
                    }
                }
            });

        return $keyed;
    }

    /**
     * One state row's worth of measurements from an EOS session.
     *
     * `next_query_at` is carried even when it is not moving — the UPDATE
     * writes the same column list for every row, and leaving it out would
     * strip the schedule off every row that was not due this pass.
     *
     * `steam_seen_at` is deliberately not written. It is what the dispatcher
     * reads to decide whether the UDP poller should skip a server, and EOS
     * listing a session says nothing about Steam having seen it. Leaving the
     * column alone keeps its previous value (usually null for an ASA row) and
     * the dispatcher's rule stays right — ASA is skipped by protocol, not by
     * Steam-visibility.
     *
     * @return array<string, mixed>
     */
    private function snapshot(DiscoveredEosServer $found, bool $due, int $id, ?int $nextQueryAt): array
    {
        return [
            'server_id' => $id,
            'game_id' => $this->game->id,
            'status' => ServerStatus::Online->value,
            'players_online' => $found->playersOnline,
            'players_max' => $found->playersMax,
            'players_queued' => 0,
            'map' => $found->map,
            'reported_version' => $found->version,
            'motd' => $found->name,
            'last_queried_at' => $this->now,
            'last_online_at' => $this->now,
            // A listed session is an alive session; a run of prior failures
            // (from A2S trying and timing out) ends here the same way one
            // ends after a successful query.
            'failed_queries_count' => 0,
            'next_query_at' => $due
                ? $this->now->copy()->addSeconds($this->schedule->intervalFor($found->playersOnline, false))
                : Carbon::createFromTimestamp($nextQueryAt),
            'updated_at' => $this->now,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function newRow(DiscoveredEosServer $found): array
    {
        $location = $this->geo->locate($found->ip);
        $countryId = $location?->countryCode !== null ? ($this->countries[$location->countryCode] ?? null) : null;

        return [
            'game_id' => $this->game->id,
            'host' => $found->ip,
            'port' => $found->port,
            // One port per session, and the dispatcher does not aim any UDP
            // at these anyway (query_protocol = eos, unsupported by the
            // driver). Stored as the port itself for consistency with the
            // gamemonitoring-imported rows this catalog already carries.
            'query_port' => $found->port,
            'ip_address' => $found->ip,
            'slug' => $this->slug($found),
            'name' => $found->name,
            'country_id' => $countryId,
            'city' => $location?->city,
            'is_active' => true,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function newState(int $serverId, DiscoveredEosServer $found): array
    {
        return [
            'server_id' => $serverId,
            'game_id' => $this->game->id,
            'status' => ServerStatus::Online->value,
            'players_online' => $found->playersOnline,
            'players_max' => $found->playersMax,
            'players_queued' => 0,
            'map' => $found->map,
            'reported_version' => $found->version,
            'motd' => $found->name,
            'last_queried_at' => $this->now,
            'last_online_at' => $this->now,
            'next_query_at' => $this->now->copy()->addSeconds($this->schedule->intervalFor($found->playersOnline, false)),
            'failed_queries_count' => 0,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ];
    }

    /**
     * Postgres types for the state-update VALUES join. Same trick
     * SteamCatalogSync uses: `jsonb_to_recordset` needs a typed row shape.
     */
    private const TYPES = [
        'server_id' => 'bigint',
        'game_id' => 'bigint',
        'status' => 'varchar',
        'players_online' => 'integer',
        'players_max' => 'integer',
        'players_queued' => 'integer',
        'map' => 'varchar',
        'reported_version' => 'varchar',
        'motd' => 'text',
        'last_queried_at' => 'timestamp',
        'last_online_at' => 'timestamp',
        'failed_queries_count' => 'integer',
        'next_query_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    private function flushStateUpdates(): void
    {
        if ($this->stateUpdates === []) {
            return;
        }

        $started = hrtime(true);

        usort(
            $this->stateUpdates,
            static fn (array $a, array $b) => [$a['game_id'], $a['server_id']] <=> [$b['game_id'], $b['server_id']],
        );

        foreach (array_chunk($this->stateUpdates, self::CHUNK) as $chunk) {
            $this->updateChunk($chunk);
        }

        $this->spent['db'] += (hrtime(true) - $started) / 1e6;
        $this->stateUpdates = [];
    }

    /**
     * Five hundred differing rows, one statement, one bound parameter — same
     * jsonb-recordset shape SteamCatalogSync writes with, see there for why.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function updateChunk(array $rows): void
    {
        $columns = array_keys($rows[0]);
        $assign = array_values(array_diff($columns, ['server_id', 'game_id']));

        $payload = [];

        foreach ($rows as $row) {
            foreach ($row as $column => $value) {
                $row[$column] = $value instanceof Carbon ? $value->toDateTimeString() : $value;
            }

            $payload[] = $row;
        }

        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);

        $set = implode(', ', array_map(
            fn (string $c) => "\"{$c}\" = v.\"{$c}\"",
            $assign,
        ));

        DB::update(
            'update "server_states" set '.$set.' from '.$this->source($columns)
            .' where "server_states"."server_id" = v."server_id"'
            .' and "server_states"."game_id" = v."game_id"',
            [$json],
        );
    }

    /**
     * @param  list<string>  $columns
     */
    private function source(array $columns): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $typed = implode(', ', array_map(fn (string $c) => "\"{$c}\" ".self::TYPES[$c], $columns));

            return "jsonb_to_recordset(?::jsonb) as v({$typed})";
        }

        $selects = implode(', ', array_map(
            fn (string $c) => "json_extract(\"value\", '$.\"{$c}\"') as \"{$c}\"",
            $columns,
        ));

        return "(select {$selects} from json_each(?)) as v";
    }

    private function flushInserts(): void
    {
        if ($this->inserts === []) {
            return;
        }

        $started = hrtime(true);

        $this->disambiguate($this->inserts);

        $bySlug = [];

        foreach ($this->inserts as $index => $row) {
            $bySlug[$row['slug']] = $this->pendingStates[$index] ?? null;
        }

        foreach (array_chunk($this->inserts, self::CHUNK) as $chunk) {
            Server::query()->insert($chunk);
        }

        $ids = DB::table('servers')
            ->where('game_id', $this->game->id)
            ->whereIn('slug', array_column($this->inserts, 'slug'))
            ->pluck('id', 'slug');

        foreach ($ids as $slug => $id) {
            $found = $bySlug[$slug] ?? null;

            if ($found === null) {
                continue;
            }

            $this->stateInserts[] = $this->newState((int) $id, $found);
        }

        $this->inserts = [];
        $this->pendingStates = [];
        $this->spent['db'] += (hrtime(true) - $started) / 1e6;

        $this->flushStateInserts();
    }

    private function flushStateInserts(): void
    {
        if ($this->stateInserts === []) {
            return;
        }

        $started = hrtime(true);

        foreach (array_chunk($this->stateInserts, self::CHUNK) as $chunk) {
            DB::table('server_states')->insert($chunk);
        }

        $this->stateInserts = [];
        $this->spent['db'] += (hrtime(true) - $started) / 1e6;
    }

    private function slug(DiscoveredEosServer $found): string
    {
        $suffix = str_replace([':', '.'], '-', $found->ip).'-'.$found->port;
        $base = Str::limit(Str::slug($found->name), 60, '');

        return $base === '' ? $suffix : "{$base}-{$suffix}";
    }

    /**
     * Settle slug collisions for one batch, in one query. Same approach
     * SteamCatalogSync uses.
     *
     * @param  list<array<string, mixed>>  &$rows
     */
    private function disambiguate(array &$rows): void
    {
        $taken = DB::table('servers')
            ->whereIn('slug', array_column($rows, 'slug'))
            ->pluck('slug')
            ->flip();

        foreach ($rows as $index => $row) {
            $slug = $row['slug'];

            for ($n = 2; $taken->has($slug); $n++) {
                $slug = $row['slug']."-{$n}";
            }

            $taken->put($slug, true);
            $rows[$index]['slug'] = $slug;
        }
    }
}
