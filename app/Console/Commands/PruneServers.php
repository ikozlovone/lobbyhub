<?php

namespace App\Console\Commands;

use App\Enums\ServerStatus;
use App\Models\Game;
use App\Models\Server;
use App\Services\Catalog\CatalogCounters;
use App\Services\Stats\ClickHouseClient;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

class PruneServers extends Command
{
    use ConfirmableTrait;

    protected $signature = 'servers:prune
        {--offline-days=7 : Delete servers that have not answered for this many days (0 turns the rule off)}
        {--empty-days=7 : Delete servers that answer but have had nobody on them for this many days (0 turns the rule off)}
        {--game= : One game, by slug}
        {--limit= : Delete at most this many, oldest first}
        {--include-claimed : Delete claimed servers too, which is off by default}
        {--dry : Report what would go and change nothing}
        {--force : Skip the confirmation}';

    protected $description = 'Remove servers nobody has reached or played on for a week';

    /**
     * How many samples a server needs inside the window before "nobody played
     * on it" is a finding rather than a gap in our own watching.
     *
     * Twenty-four a day is one an hour, against the sweeper's cadence of one
     * every ten minutes. A server we barely looked at is not a server nobody
     * plays, and deleting it on that evidence is the mistake this number is
     * here to prevent.
     */
    private const SAMPLES_PER_DAY = 24;

    /**
     * The catalog's own gardening.
     *
     * Two rules, because a dead listing is dead in two different ways. A
     * server that stopped answering is gone — the machine is off, the address
     * was recycled, the owner moved on. A server that answers perfectly and
     * has had nobody on it for a week is alive and pointless: it is a page
     * with nothing on it, in a catalog whose listings are judged by whether
     * they lead anywhere.
     *
     * Both are soft deletes. The row keeps its slug — that URL has been public
     * — the listings stop showing it, the monitor stops polling it, and an
     * owner who comes back can have it restored rather than re-created as a
     * duplicate with a `-2` on the end.
     *
     * Promoted servers are never touched: somebody paid for that placement.
     * Claimed ones are spared by default too, because a person is attached to
     * them and "your server was deleted while you were away" is a worse
     * message than a stale listing — `--include-claimed` for when that is the
     * intent.
     */
    public function handle(CatalogCounters $counters, ClickHouseClient $ch): int
    {
        $offlineDays = (int) $this->option('offline-days');
        $emptyDays = (int) $this->option('empty-days');

        if ($offlineDays < 1 && $emptyDays < 1) {
            $this->error('Both rules are off. Give --offline-days, --empty-days, or both.');

            return self::FAILURE;
        }

        $games = $this->games();

        if ($games === null) {
            return self::FAILURE;
        }

        $offline = $offlineDays > 0 ? $this->offline($games, $offlineDays) : collect();
        $empty = $emptyDays > 0 ? $this->empty($games, $emptyDays, $ch) : collect();

        // A server can qualify under both rules; it is still one server.
        $doomed = $offline->merge($empty)->unique('id');

        if ($limit = (int) $this->option('limit')) {
            $doomed = $doomed->sortBy('created_at')->take($limit);
        }

        $this->report($doomed, $offline->count(), $empty->count());

        if ($doomed->isEmpty()) {
            return self::SUCCESS;
        }

        if ($this->option('dry')) {
            $this->warn("Dry run: {$doomed->count()} server(s) would be removed.");

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed("This removes {$doomed->count()} server(s) from the catalog")) {
            return self::FAILURE;
        }

        // In chunks, because `whereIn` on tens of thousands of ids is one
        // enormous statement and this has no reason to be a single one.
        foreach ($doomed->pluck('id')->chunk(500) as $chunk) {
            Server::query()->whereIn('id', $chunk)->delete();
        }

        $this->info("{$doomed->count()} server(s) removed.");

        /*
         * Every count on the site now says one thing and the catalog another.
         *
         * CatalogCounters does the whole job in one call: it recomputes the
         * denormalised counts on games, modes, versions and countries, flushes
         * the API's own short cache, forgets the cached listings of the games
         * whose shape changed, and tells the frontend to revalidate. Which is
         * why it is called here rather than each of those being done by hand.
         */
        $this->line('  Recounting the catalog…');
        $counts = $counters->refresh();

        $this->info(sprintf(
            '  Counters rewritten: %s.',
            collect($counts)->map(fn ($rows, $table) => "{$rows} {$table}")->implode(', '),
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Game>|null
     */
    private function games(): ?Collection
    {
        $slug = $this->option('game');

        if (! $slug) {
            return Game::query()->orderBy('id')->get();
        }

        $game = Game::query()->where('slug', $slug)->first();

        if ($game === null) {
            $this->error("No game with slug {$slug}.");

            return null;
        }

        return collect([$game]);
    }

    /**
     * Servers that have not answered in this long.
     *
     * `last_online_at` is when one last replied to us. Null means it never
     * has, which is the submission that was a typo or the discovery that was
     * never reachable — those are judged on when the row was created instead,
     * so a server added this morning is not deleted this afternoon for not
     * having answered yet.
     *
     * @param  Collection<int, Game>  $games
     * @return Collection<int, Server>
     */
    private function offline(Collection $games, int $days): Collection
    {
        $cutoff = now()->subDays($days);

        return $this->candidates($games)
            ->join('server_states', function ($join) {
                $join->on('server_states.server_id', '=', 'servers.id')
                    ->on('server_states.game_id', '=', 'servers.game_id');
            })
            ->where('server_states.status', '!=', ServerStatus::Online->value)
            ->where(function ($query) use ($cutoff) {
                $query->where('server_states.last_online_at', '<', $cutoff)
                    ->orWhere(fn ($q) => $q->whereNull('server_states.last_online_at')
                        ->where('servers.created_at', '<', $cutoff));
            })
            ->select(['servers.id', 'servers.slug', 'servers.game_id', 'servers.created_at'])
            ->get()
            ->each(fn (Server $server) => $server->setAttribute('prune_reason', 'offline'));
    }

    /**
     * Servers that answer and have had nobody on them.
     *
     * The only place that knows is ClickHouse: `server_players_raw` is a
     * reading per server per sweep, so a week of them with a peak of zero is a
     * week of nobody playing. Postgres has the current count and no memory of
     * it — which is exactly why this rule cannot be written there.
     *
     * The raw table keeps seven days, so a longer window is answered with what
     * survives rather than with nothing. That never deletes more than it
     * should — it only means `--empty-days=30` cannot tell thirty days from
     * eight — and the caller is told so.
     *
     * @param  Collection<int, Game>  $games
     * @return Collection<int, Server>
     */
    private function empty(Collection $games, int $days, ClickHouseClient $ch): Collection
    {
        if (! $ch->isConfigured()) {
            $this->warn('  No ClickHouse configured — the "nobody played" rule needs the sample history, so it is skipped.');

            return collect();
        }

        if ($days > 7) {
            $this->warn("  server_players_raw keeps seven days, so --empty-days={$days} is judged on the seven there are.");
        }

        try {
            $rows = $ch->query(
                'SELECT server_id
                   FROM server_players_raw
                  WHERE game_id IN ({games:Array(UInt32)})
                    AND ts >= now() - INTERVAL {days:UInt16} DAY
                  GROUP BY server_id
                 HAVING max(players_online) = 0
                    AND count() >= {samples:UInt32}',
                [
                    // ClickHouse takes an array parameter as a bracketed list.
                    'games' => '['.$games->pluck('id')->implode(',').']',
                    'days' => $days,
                    'samples' => $days * self::SAMPLES_PER_DAY,
                ],
            );
        } catch (RuntimeException $e) {
            $this->warn('  ClickHouse would not answer, so the "nobody played" rule is skipped: '.$e->getMessage());

            return collect();
        }

        $ids = array_map(fn (array $row) => (int) $row['server_id'], $rows);

        if ($ids === []) {
            return collect();
        }

        return $this->candidates($games)
            ->whereIn('servers.id', $ids)
            ->select(['servers.id', 'servers.slug', 'servers.game_id', 'servers.created_at'])
            ->get()
            ->each(fn (Server $server) => $server->setAttribute('prune_reason', 'empty'));
    }

    /**
     * What either rule is allowed to look at in the first place.
     *
     * @param  Collection<int, Game>  $games
     */
    private function candidates(Collection $games): Builder
    {
        return Server::query()
            ->whereIn('servers.game_id', $games->pluck('id'))
            // Paid placement is not something a cleanup gets to end.
            ->where(fn ($query) => $query->whereNull('promoted_until')
                ->orWhere('promoted_until', '<=', now()))
            ->when(! $this->option('include-claimed'), fn ($query) => $query->whereNull('user_id'));
    }

    /** @param  Collection<int, Server>  $doomed */
    private function report(Collection $doomed, int $offline, int $empty): void
    {
        if ($doomed->isEmpty()) {
            $this->info('Nothing to remove.');

            return;
        }

        $names = Game::query()->pluck('slug', 'id');

        foreach ($doomed->groupBy('game_id') as $gameId => $servers) {
            $this->line(sprintf('  %-28s %5d server(s)', $names[$gameId] ?? $gameId, $servers->count()));

            foreach ($servers->take(5) as $server) {
                $this->line(sprintf(
                    '      %-46s %s',
                    $server->slug,
                    $server->getAttribute('prune_reason'),
                ));
            }

            if ($servers->count() > 5) {
                $this->line(sprintf('      … and %d more', $servers->count() - 5));
            }
        }

        $this->newLine();
        $this->line("  {$offline} not answering · {$empty} answering with nobody on them");
    }
}
