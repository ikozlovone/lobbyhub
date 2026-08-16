<?php

namespace App\Services\Discovery;

use App\Enums\ServerStatus;
use App\Models\Country;
use App\Models\Game;
use App\Models\Server;
use App\Models\ServerStat;
use App\Services\Geo\GeoResolver;
use App\Services\Monitoring\PollingSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes a sweep into the catalog, one server at a time and none of them twice.
 *
 * Nothing here is an Eloquent save: row by row would be forty thousand round
 * trips for Counter-Strike alone. Rows arrive from the sweep as it reads them,
 * are turned into payload arrays, and go down in statements of five hundred.
 *
 * Memory is the constraint that shaped this, not speed. The first version
 * collected a game and then wrote it, and Counter-Strike killed a 128 MB
 * process without printing a word. What is held now is the existing rows'
 * addresses and three fields each, and one batch in
 * flight — none of which grows with how large the game turns out to be.
 *
 * Two rules decide what is written, and they are not the same rule:
 *
 *  - **The snapshot** — players, map, version, tags — is rewritten every sweep.
 *    It is what a visitor sees, it costs one column write, and there is no
 *    reason to show anything older than the last sweep.
 *  - **The history** follows the polling tiers exactly as it did when a UDP
 *    packet wrote it. Recording every server every five minutes would multiply
 *    server_stats by the ratio between the sweep interval and the tier a server
 *    actually sits in — for the quiet ones that is twelvefold, on the largest
 *    table in the schema, for graphs nobody reads at that resolution.
 *
 * What is deliberately not touched: `name` and `slug`. An owner may have edited
 * the first and the second is a public URL. The server's own current name lands
 * in `motd`, which is where the page reads it from anyway.
 */
class SteamCatalogSync
{
    /** Rows per statement. Twenty-odd columns each, well inside Postgres's parameter ceiling. */
    private const CHUNK = 500;

    private Game $game;

    private Carbon $now;

    /** @var array<string, array{0: int, 1: ?int, 2: bool}> id, next_query_at, deleted */
    private array $byAddress = [];

    /** @var Collection<string, int> */
    private Collection $countries;

    /** @var list<array<string, mixed>> */
    private array $updates = [];

    /** @var list<array<string, mixed>> */
    private array $inserts = [];

    /** @var list<array<string, mixed>> */
    private array $samples = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** Milliseconds, by phase. @var array<string, float> */
    private array $spent = [];

    public function __construct(
        private readonly PollingSchedule $schedule,
        private readonly GeoResolver $geo,
    ) {}

    public function run(Game $game, ServerSweep $sweep, bool $populatedOnly = false): SyncReport
    {
        $startedAt = hrtime(true);

        $this->game = $game;
        $this->now = now();
        $this->updates = $this->inserts = $this->samples = [];
        $this->counts = ['updated' => 0, 'created' => 0, 'sampled' => 0, 'skipped' => 0];
        $this->spent = ['db' => 0.0, 'existing' => 0.0];

        $loading = hrtime(true);
        $this->byAddress = $this->existing($game);
        $this->countries = Country::query()->pluck('id', 'code');
        $this->spent['existing'] = (hrtime(true) - $loading) / 1e6;

        /*
         * The catalog's own addresses, handed to the sweep as a filter.
         *
         * Only when the sweep is not allowed to create rows — which is the
         * default. In that mode a row Steam offers for an address we do not
         * hold has exactly one destiny, `skipped`, and there is no reason to
         * parse it to find that out. With creation on the set is withheld, or
         * the sweep would filter out precisely the rows it is meant to add.
         */
        $only = config('monitoring.steam_create_new_servers', false) ? null : $this->byAddress;

        $result = $sweep->stream($game, fn (DiscoveredServer $found) => $this->write($found), $populatedOnly, $only);

        $this->flushUpdates();
        $this->flushInserts();
        $this->flushSamples();

        $totalMs = (hrtime(true) - $startedAt) / 1e6;

        /*
         * Whatever is left over after the three measured phases: JSON decoding,
         * address parsing, building the payload rows. Derived rather than timed
         * because timing it directly means two hrtime calls per server, and at a
         * hundred thousand servers the measurement starts showing up in what it
         * measures.
         */
        $rowsMs = max(0.0, $totalMs - $result->httpMs - $this->spent['db'] - $this->spent['existing']);

        return new SyncReport(
            found: $result->found,
            updated: $this->counts['updated'],
            created: $this->counts['created'],
            sampled: $this->counts['sampled'],
            requests: $result->requests,
            truncated: $result->truncated,
            unreachable: $result->unreachable,
            skipped: $this->counts['skipped'] + $result->skipped,
            totalMs: $totalMs,
            steamMs: $result->httpMs,
            rowsMs: $rowsMs,
            dbMs: $this->spent['db'],
            existingMs: $this->spent['existing'],
        );
    }

    private function write(DiscoveredServer $found): void
    {
        $existing = $this->byAddress[$found->ip.':'.$found->gamePort] ?? null;

        // A row somebody deleted stays deleted. Skipped rather than inserted,
        // or the sweep would put it straight back every cycle.
        if ($existing !== null && $existing[2]) {
            return;
        }

        if ($existing === null) {
            /*
             * Sweep-created rows are gated by config. Off, the catalog only
             * grows through the submission form and the admin import, and a
             * server Steam is showing us for the first time is counted and
             * dropped. Updates still land — the sweep is what keeps the
             * catalog fresh — but the population stops moving on its own.
             */
            if (! config('monitoring.steam_create_new_servers', false)) {
                $this->counts['skipped']++;

                return;
            }

            $this->inserts[] = $this->newRow($found);
            $this->counts['created']++;
        } else {
            [$id, $nextQueryAt] = $existing;
            $due = $nextQueryAt === null || $nextQueryAt <= $this->now->getTimestamp();

            $this->updates[] = $this->snapshot($found, $due, $id, $nextQueryAt);
            $this->counts['updated']++;

            if ($due) {
                $this->samples[] = $this->sample($id, $found->playersOnline, $found->playersMax);
                $this->counts['sampled']++;
            }
        }

        if (count($this->updates) >= self::CHUNK) {
            $this->flushUpdates();
        }

        if (count($this->inserts) >= self::CHUNK) {
            $this->flushInserts();
        }

        if (count($this->samples) >= self::CHUNK) {
            $this->flushSamples();
        }
    }

    /**
     * Every row this game already has, keyed by both addresses it might answer to.
     *
     * Two keys, not one. Discovery writes `host` as the IP it found, but an
     * owner who submitted through the form may have typed a domain — and then
     * the address Steam reports would look like a server we do not have, and we
     * would list it twice. `ip_address` is what the monitor resolved, so it is
     * the second way in.
     *
     * Three scalars per row rather than a model or even a record: at thirty
     * thousand servers the difference between this and hydrated Eloquent is the
     * difference between running and being killed.
     *
     * @return array<string, array{0: int, 1: ?int, 2: bool}>
     */
    private function existing(Game $game): array
    {
        $keyed = [];

        /*
         * chunkById rather than chunk, which pages with offset and limit: the
         * last page of a game with ninety thousand servers asks Postgres for
         * rows 88 000 to 90 000, and it can only answer by producing the
         * eighty-eight thousand before them and throwing them away. Keyset
         * paging asks `where id > last` instead, so every page costs what the
         * first one did.
         *
         * How much it is worth depends entirely on where it runs, which is why
         * the local measurement was misleading: on a laptop against a local
         * Postgres it is 682 ms against 554 ms at ninety thousand rows — small
         * enough to write off, and written off in the first draft of this
         * comment. On the deployed box, where the connection goes through
         * pgbouncer and the disk is not an M-series SSD, the same change took
         * this phase from 36.3 s to 7.4 s of a Counter-Strike sweep.
         *
         * The discarded rows are not free anywhere. They are just cheap enough
         * on good hardware to hide behind everything else.
         */
        DB::table('servers')
            ->where('game_id', $game->id)
            ->select(['id', 'host', 'port', 'ip_address', 'game_port', 'next_query_at', 'deleted_at'])
            ->chunkById(2000, function (Collection $rows) use (&$keyed) {
                foreach ($rows as $row) {
                    $entry = [
                        (int) $row->id,
                        $row->next_query_at === null ? null : Carbon::parse($row->next_query_at)->getTimestamp(),
                        $row->deleted_at !== null,
                    ];

                    $keyed[$row->host.':'.$row->port] ??= $entry;

                    if ($row->ip_address !== null) {
                        /*
                         * Both ports, because the row may only have one of them.
                         *
                         * `game_port` is written from the extra data block of an
                         * A2S reply, and not every server sends it — so a server
                         * somebody submitted by domain can have a resolved IP and
                         * no game port at all. Steam then reports an address that
                         * matches neither the host nor the pair below it, and the
                         * sweep lists the same machine a second time. `port` is
                         * what the owner typed as the connect port, which is what
                         * Steam calls `gameport`.
                         */
                        $keyed[$row->ip_address.':'.$row->port] ??= $entry;

                        if ($row->game_port !== null) {
                            $keyed[$row->ip_address.':'.$row->game_port] ??= $entry;
                        }
                    }
                }
            });

        return $keyed;
    }

    /**
     * One row's worth of measurements.
     *
     * `next_query_at` is carried even when it is not moving, because an upsert
     * writes the same column list for every row and leaving it out would strip
     * the schedule off everything that was not due.
     *
     * @return array<string, mixed>
     */
    private function snapshot(DiscoveredServer $found, bool $due, int $id, ?int $nextQueryAt): array
    {
        $row = [
            'id' => $id,
            'status' => ServerStatus::Online->value,
            'players_online' => $found->playersOnline,
            'players_max' => $found->playersMax,
            'players_queued' => $found->playersQueued ?? 0,
            'map' => $found->map,
            'reported_version' => $found->version,
            'wiped_at' => $found->wipedAt,
            'motd' => $found->name,
            'ip_address' => $found->ip,
            'game_port' => $found->gamePort,
            'query_port' => $found->queryPort,
            'last_queried_at' => $this->now,
            'last_online_at' => $this->now,
            'steam_seen_at' => $this->now,
            // Steam listing it is Steam having heard from it, so a run of
            // failures ends here the same way an answered query ends it.
            'failed_queries_count' => 0,
            'next_query_at' => $due
                ? $this->now->copy()->addSeconds($this->interval($found))
                : Carbon::createFromTimestamp($nextQueryAt),
            'updated_at' => $this->now,
        ];

        /*
         * Always carried, even as null, and the null is what the SET clause
         * reads as "leave this one alone" — see SOFT.
         *
         * They used to be added only when present, which gave a batch as many
         * shapes as there are combinations of the three, and one statement can
         * only have one shape. Five hundred rows arriving as eight groups of
         * sixty is eight statements doing the work of one, and which of the
         * eight a server lands in depends on what its game happens to report.
         * Absent from a payload still is not the same as zero — that part is
         * now the SET clause's job rather than the array's.
         */
        $row['steam_id'] = $found->steamId;
        $row['bots'] = $found->bots;
        $row['vac_enabled'] = $found->vacEnabled;

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function newRow(DiscoveredServer $found): array
    {
        $location = $this->geo->locate($found->ip);
        $countryId = $location?->countryCode !== null ? ($this->countries[$location->countryCode] ?? null) : null;

        return [
            'game_id' => $this->game->id,
            'host' => $found->ip,
            'port' => $found->gamePort,
            'query_port' => $found->queryPort,
            'ip_address' => $found->ip,
            'game_port' => $found->gamePort,
            'slug' => $this->slug($found),
            'name' => $found->name,
            'motd' => $found->name,
            'map' => $found->map,
            'reported_version' => $found->version,
            'wiped_at' => $found->wipedAt,
            'players_queued' => $found->playersQueued ?? 0,
            'players_online' => $found->playersOnline,
            'players_max' => $found->playersMax,
            'steam_id' => $found->steamId,
            'bots' => $found->bots,
            'vac_enabled' => $found->vacEnabled,
            'country_id' => $countryId,
            'city' => $location?->city,
            /*
             * Online, not unknown.
             *
             * Discovery used to write candidates and wait for our own query to
             * publish them, because a row out of Steam's cache was hearsay. A
             * sweep is not that: the server is in the list because it registered
             * with Steam's master and is answering it, and the numbers are the
             * same self-report a query would have returned. Holding twenty
             * thousand of those back for a packet that adds nothing is the cost
             * this path exists to remove.
             */
            'status' => ServerStatus::Online->value,
            'last_queried_at' => $this->now,
            'last_online_at' => $this->now,
            'steam_seen_at' => $this->now,
            'next_query_at' => $this->now->copy()->addSeconds($this->interval($found)),
            'is_active' => true,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ];
    }

    /**
     * The tier for a server we have no model of.
     *
     * PollingSchedule reads one field off an unpromoted server, and hydrating
     * forty thousand Eloquent objects to hand it over is exactly the cost this
     * class exists to avoid.
     */
    private function interval(DiscoveredServer $found): int
    {
        $proxy = new Server;
        $proxy->players_online = $found->playersOnline;
        $proxy->promoted_until = null;

        return $this->schedule->intervalFor($proxy);
    }

    /**
     * @return array<string, mixed>
     */
    private function sample(int $serverId, int $players, int $maxPlayers): array
    {
        return [
            'server_id' => $serverId,
            'recorded_at' => $this->now,
            'is_online' => true,
            'players_online' => $players,
            'players_max' => $maxPlayers,
            // No packet went out, so there is nothing to time. Null rather than
            // a zero, which would read as a perfect connection.
            'latency_ms' => null,
        ];
    }

    /**
     * The Postgres type of every column this writes, for the VALUES list below.
     *
     * A join against VALUES has no table to take types from, and the first row
     * decides them — so they are pinned rather than inferred, which a leading
     * null would otherwise get wrong.
     */
    private const TYPES = [
        'id' => 'bigint',
        'status' => 'varchar',
        'players_online' => 'integer',
        'players_max' => 'integer',
        'players_queued' => 'integer',
        'map' => 'varchar',
        'reported_version' => 'varchar',
        'wiped_at' => 'timestamp',
        'motd' => 'varchar',
        'ip_address' => 'varchar',
        'game_port' => 'integer',
        'query_port' => 'integer',
        'last_queried_at' => 'timestamp',
        'last_online_at' => 'timestamp',
        'steam_seen_at' => 'timestamp',
        'failed_queries_count' => 'integer',
        'next_query_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'steam_id' => 'varchar',
        'bots' => 'integer',
        'vac_enabled' => 'boolean',
    ];

    /**
     * A real UPDATE, and not an upsert, which is the shape this started as and
     * could never have worked.
     *
     * `ON CONFLICT (id) DO UPDATE` looked like the way to write five hundred
     * differing rows in one statement, and it is — for a payload that carries
     * every non-nullable column. Postgres checks NOT NULL against the row an
     * insert *proposes*, before it consults the conflict target, so a partial
     * payload aimed at an existing id fails on `game_id` without ever reaching
     * the DO UPDATE branch. Measured exactly that way: "null value in column
     * game_id violates not-null constraint", on ids that were sitting in the
     * table the whole time.
     *
     * Joining against a VALUES list has none of that. It cannot insert, so
     * columns it does not mention are simply not its business, and a row whose
     * id has since gone is a no-op rather than an error.
     */
    private function flushUpdates(): void
    {
        if ($this->updates === []) {
            return;
        }

        $started = hrtime(true);

        /*
         * Sorted by id before it goes down. Five hundred rows is a cheap sort
         * and it turns five hundred scattered heap pages into a walk in page
         * order — the same reason a bulk load is written sorted.
         */
        usort($this->updates, static fn (array $a, array $b) => $a['id'] <=> $b['id']);

        foreach (array_chunk($this->updates, self::CHUNK) as $chunk) {
            $this->updateChunk($chunk);
        }

        $this->spent['db'] += (hrtime(true) - $started) / 1e6;
        $this->updates = [];
    }

    /**
     * The three columns a null must not overwrite.
     *
     * Absent from a Steam payload is not the same as zero: a game that never
     * reports bots should keep whatever the last real answer was, and one that
     * reports them intermittently should not lose the count on the sweeps that
     * do not carry it.
     */
    private const SOFT = ['steam_id', 'bots', 'vac_enabled'];

    /**
     * Five hundred differing rows, one statement, one bound parameter.
     *
     * The shape this replaces was `select ? … union all select ? …`, one branch
     * per row: at twenty-one columns that is ten thousand five hundred
     * placeholders for a batch, every one of them parsed, planned and bound
     * individually, and a statement text that is a hundred kilobytes of SQL the
     * server has never seen before and will never see again. It also could not
     * batch past two hundred, because SQLite gives up past five hundred
     * compound selects — so the largest game paid for the smallest engine.
     *
     * Handing the batch over as one JSON document instead moves the row work
     * from the parser into a function scan: one placeholder, one plan, and a
     * statement text that is identical every time and can therefore be reused.
     *
     * Both engines can read it, which is the property the old shape was chosen
     * for and this one keeps — Postgres with `jsonb_to_recordset`, SQLite with
     * `json_each`. A bulk path no test can reach is a bulk path nobody knows is
     * broken.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function updateChunk(array $rows): void
    {
        $columns = array_keys($rows[0]);
        $assign = array_values(array_diff($columns, ['id']));

        $payload = [];

        foreach ($rows as $row) {
            foreach ($row as $column => $value) {
                $row[$column] = $value instanceof Carbon ? $value->toDateTimeString() : $value;
            }

            $payload[] = $row;
        }

        /*
         * `SUBSTITUTE` is belt and braces rather than a fix for something seen:
         * every value here came out of a `json_decode` of Steam's response, so
         * it is valid UTF-8 already, and `mb_substr` cuts on character
         * boundaries rather than bytes. What it guarantees is that if a row ever
         * reaches this from somewhere with weaker promises, one bad byte does
         * not fail the encode and take the other four hundred and ninety-nine
         * rows down with it.
         */
        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);

        $set = implode(', ', array_map(
            fn (string $c) => in_array($c, self::SOFT, true)
                ? "\"{$c}\" = coalesce(v.\"{$c}\", \"servers\".\"{$c}\")"
                : "\"{$c}\" = v.\"{$c}\"",
            $assign,
        ));

        DB::update('update "servers" set '.$set.' from '.$this->source($columns).' where "servers"."id" = v."id"', [$json]);
    }

    /**
     * The JSON document, read back as a table of typed columns.
     *
     * Postgres is told the types up front, which is what `jsonb_to_recordset`
     * requires and also what makes a null land as a null of the right type
     * rather than as text. SQLite has no typed form and does not need one — it
     * takes whatever `json_extract` gives back and compares it happily.
     *
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

        $slugs = array_column($this->inserts, 'slug');
        $players = array_column($this->inserts, 'players_online', 'slug');
        $maxPlayers = array_column($this->inserts, 'players_max', 'slug');

        foreach (array_chunk($this->inserts, self::CHUNK) as $chunk) {
            Server::query()->insert($chunk);
        }

        /*
         * Their first reading, against the ids the insert minted. Without it a
         * new server's graph starts whenever it next falls due rather than when
         * it joined, which on the quiet tier is an hour of nothing.
         */
        $ids = DB::table('servers')
            ->where('game_id', $this->game->id)
            ->whereIn('slug', $slugs)
            ->pluck('id', 'slug');

        foreach ($ids as $slug => $id) {
            $this->samples[] = $this->sample((int) $id, $players[$slug] ?? 0, $maxPlayers[$slug] ?? 0);
            $this->counts['sampled']++;
        }

        $this->inserts = [];
        $this->spent['db'] += (hrtime(true) - $started) / 1e6;

        $this->flushSamples();
    }

    private function flushSamples(): void
    {
        if ($this->samples === []) {
            return;
        }

        $started = hrtime(true);

        foreach (array_chunk($this->samples, self::CHUNK) as $chunk) {
            // upsert, like the job's own recording: two writes landing in the
            // same second must not collide on (server_id, recorded_at).
            ServerStat::query()->upsert(
                $chunk,
                ['server_id', 'recorded_at'],
                ['is_online', 'players_online', 'players_max', 'latency_ms'],
            );
        }

        $this->samples = [];
        $this->spent['db'] += (hrtime(true) - $started) / 1e6;
    }

    /**
     * The slug a server would like to have.
     *
     * Server::slugFor asks the database whether each candidate is free, which
     * is right for one submission and is a hundred thousand round trips here.
     * The address is part of the base, so within one game these are unique by
     * construction; the only way to collide is two games behind one address,
     * and `disambiguate` settles that for a whole batch at once.
     */
    private function slug(DiscoveredServer $found): string
    {
        $suffix = str_replace([':', '.'], '-', $found->ip).'-'.$found->gamePort;
        $base = Str::limit(Str::slug($found->name), 60, '');

        return $base === '' ? $suffix : "{$base}-{$suffix}";
    }

    /**
     * Settle slug collisions for one batch, in one query.
     *
     * The obvious version held every slug in the catalog in a set, and that set
     * is the one structure here that grows with the whole site rather than with
     * a batch — a hundred and twenty thousand of them once Counter-Strike has
     * landed. Asking about the five hundred slugs actually being written costs
     * one `where in` and nothing that survives the call.
     *
     * @param  list<array<string, mixed>>  $rows
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

            // Put back so the rest of this batch collides with it too: two new
            // servers can want the same slug just as easily as a new one and a
            // stored one.
            $taken->put($slug, true);
            $rows[$index]['slug'] = $slug;
        }
    }
}
