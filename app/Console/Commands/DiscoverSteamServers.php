<?php

namespace App\Console\Commands;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use App\Services\Discovery\DiscoveredServer;
use App\Services\Discovery\SteamServerDiscovery;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

class DiscoverSteamServers extends Command
{
    protected $signature = 'discovery:steam
        {--game= : Slug of a single game; omit to sweep every game with an app id}
        {--top=200 : Import at most this many servers per game, busiest first}
        {--min-players=1 : Skip servers below this player count}
        {--dry-run : Report what would be imported without writing}';

    protected $description = 'Discover servers through the Steam Web API and add them to the catalog';

    public function handle(SteamServerDiscovery $discovery): int
    {
        $games = $this->option('game')
            ? Game::query()->where('slug', $this->option('game'))->get()
            : Game::query()->whereNotNull('steam_appid')->orderBy('sort_order')->get();

        if ($games->isEmpty()) {
            $this->error('No games to sweep.');

            return self::FAILURE;
        }

        $top = (int) $this->option('top');
        $minPlayers = (int) $this->option('min-players');
        $totals = ['found' => 0, 'created' => 0, 'updated' => 0];

        foreach ($games as $game) {
            if ($game->steam_appid === null) {
                $this->warn("  {$game->slug}: no Steam app id, skipped");

                continue;
            }

            try {
                $servers = $discovery->discover($game);
            } catch (RuntimeException $exception) {
                $this->error("  {$game->slug}: {$exception->getMessage()}");

                continue;
            }

            // Busiest first, so a `--top` cut keeps the servers people browse.
            $selected = $servers
                ->filter(fn (DiscoveredServer $s) => $s->playersOnline >= $minPlayers)
                ->sortByDesc(fn (DiscoveredServer $s) => $s->playersOnline)
                ->take($top);

            $created = 0;
            $updated = 0;

            foreach ($selected as $found) {
                $this->option('dry-run')
                    ? null
                    : ($this->store($game, $found) ? $created++ : $updated++);
            }

            $totals['found'] += $servers->count();
            $totals['created'] += $created;
            $totals['updated'] += $updated;

            $this->line(sprintf(
                '  %-30s %5d found, %4d selected%s',
                $game->slug,
                $servers->count(),
                $selected->count(),
                $this->option('dry-run') ? '' : sprintf(', %d new, %d updated', $created, $updated),
            ));

            // Report a cut rather than letting it look like full coverage.
            if ($servers->count() >= SteamServerDiscovery::MAX_PER_REQUEST) {
                $this->warn("    {$game->slug} hit the API's 10 000-server ceiling; the list is truncated");
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s: %d found, %d imported, %d refreshed.',
            $this->option('dry-run') ? 'Dry run' : 'Done',
            $totals['found'],
            $totals['created'],
            $totals['updated'],
        ));

        return self::SUCCESS;
    }

    /**
     * Discovery writes candidates; it does not publish them.
     *
     * Everything here is second-hand data from Steam's cache, so a new server
     * lands with status `unknown` and is queued for our own query. The catalog
     * only lists servers we have reached ourselves.
     *
     * @return bool true when the server is new
     */
    private function store(Game $game, DiscoveredServer $found): bool
    {
        $existing = Server::withTrashed()
            ->where('game_id', $game->id)
            ->where('host', $found->ip)
            ->where('port', $found->gamePort)
            ->first();

        if ($existing !== null) {
            // Never touch name or slug: an owner may have edited them, and the
            // slug is a public URL. Ports and address facts are safe to refresh.
            $existing->forceFill([
                'query_port' => $found->queryPort,
                'ip_address' => $found->ip,
                'game_port' => $found->gamePort,
            ])->save();

            return false;
        }

        Server::create([
            'game_id' => $game->id,
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
            // Unverified until our own monitor reaches it.
            'status' => ServerStatus::Unknown,
            'players_online' => 0,
            'players_max' => $found->playersMax,
            'next_query_at' => now(),
        ]);

        return true;
    }

    /**
     * Server names are wild — emoji, unicode, decoration — so the address is
     * always appended to keep the slug unique and never empty.
     */
    private function slug(DiscoveredServer $found): string
    {
        $base = Str::limit(Str::slug($found->name), 60, '');
        $suffix = str_replace('.', '-', $found->ip).'-'.$found->gamePort;

        return $base === '' ? $suffix : "{$base}-{$suffix}";
    }
}
