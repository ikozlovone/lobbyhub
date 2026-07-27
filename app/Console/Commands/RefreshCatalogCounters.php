<?php

namespace App\Console\Commands;

use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class RefreshCatalogCounters extends Command
{
    protected $signature = 'counters:refresh';

    protected $description = 'Recompute the denormalized server and player counters used by catalog pages';

    /**
     * Catalog pages sort and filter on these counters, and every facet shows one.
     * Computing them per request would mean aggregating over the whole servers
     * table on every page view, so they are refreshed on a schedule instead.
     */
    public function handle(): int
    {
        DB::transaction(function () {
            $this->refreshGames();
            $this->refreshModes();
            $this->refreshVersions();
            $this->refreshCountries();
        });

        $this->info('Catalog counters refreshed.');

        return self::SUCCESS;
    }

    private function refreshGames(): void
    {
        $aggregates = $this->activeServers()
            ->selectRaw('game_id as key')
            ->selectRaw('count(*) as servers_count')
            ->selectRaw($this->onlineCount().' as online_servers_count')
            ->selectRaw($this->onlinePlayers().' as players_online')
            ->groupBy('game_id')
            ->get();

        $this->apply('games', $aggregates, ['servers_count', 'online_servers_count', 'players_online'], [
            'stats_synced_at' => now(),
        ]);
    }

    private function refreshModes(): void
    {
        $aggregates = $this->activeServers()
            ->join('game_mode_server as pivot', 'pivot.server_id', '=', 'servers.id')
            ->selectRaw('pivot.game_mode_id as key')
            ->selectRaw('count(*) as servers_count')
            ->selectRaw($this->onlinePlayers().' as players_online')
            ->groupBy('pivot.game_mode_id')
            ->get();

        $this->apply('game_modes', $aggregates, ['servers_count', 'players_online']);
    }

    private function refreshVersions(): void
    {
        $aggregates = $this->activeServers()
            ->whereNotNull('game_version_id')
            ->selectRaw('game_version_id as key')
            ->selectRaw('count(*) as servers_count')
            ->selectRaw($this->onlinePlayers().' as players_online')
            ->groupBy('game_version_id')
            ->get();

        $this->apply('game_versions', $aggregates, ['servers_count', 'players_online']);
    }

    private function refreshCountries(): void
    {
        $aggregates = $this->activeServers()
            ->whereNotNull('country_id')
            ->selectRaw('country_id as key')
            ->selectRaw('count(*) as servers_count')
            ->groupBy('country_id')
            ->get();

        $this->apply('countries', $aggregates, ['servers_count']);
    }

    /** Soft-deleted and delisted servers must not show up in any counter. */
    private function activeServers(): Builder
    {
        return DB::table('servers')->whereNull('deleted_at')->where('is_active', true);
    }

    /** Portable conditional aggregate: sqlite has no FILTER in older builds. */
    private function onlineCount(): string
    {
        return "sum(case when status = '".ServerStatus::Online->value."' then 1 else 0 end)";
    }

    /** Offline servers report zero players, but be explicit rather than trusting that. */
    private function onlinePlayers(): string
    {
        return "sum(case when status = '".ServerStatus::Online->value."' then players_online else 0 end)";
    }

    /**
     * Zero every row, then write the aggregates back: a facet that lost its last
     * server has to fall to zero, and it simply will not appear in the group-by.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $aggregates
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $extra
     */
    private function apply(string $table, $aggregates, array $columns, array $extra = []): void
    {
        DB::table($table)->update(array_fill_keys($columns, 0) + $extra);

        foreach ($aggregates as $row) {
            $values = [];

            foreach ($columns as $column) {
                $values[$column] = (int) ($row->{$column} ?? 0);
            }

            DB::table($table)->where('id', $row->key)->update($values + $extra);
        }

        $this->line(sprintf('  %-14s %d row(s) with servers', $table, $aggregates->count()));
    }
}
