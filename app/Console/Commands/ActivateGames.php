<?php

namespace App\Console\Commands;

use App\Models\Game;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ActivateGames extends Command
{
    protected $signature = 'games:activate
        {--game= : One game, by slug}
        {--min-players= : Games with at least this many players on Steam right now}
        {--charted : Games currently inside Steam\'s own top 100}
        {--limit= : Take at most this many, busiest first}
        {--all : Every game with a Steam app id, whatever it is}
        {--hide : Switch the matches off instead of on}
        {--dry-run : List the matches and change nothing}';

    protected $description = 'Switch imported games on (or off) in bulk, by how many people are playing them';

    /**
     * The other end of the catalog imports.
     *
     * `gamemonitoring:games` and `steamdb:games` leave their rows switched off
     * on purpose — a game arrives with a name, a slug and an appid and none of
     * what a page is made of. Turning them on one at a time in the admin is
     * fine for three and absurd for three hundred, and the useful way to pick
     * is the one number the collector has already read: how many people are
     * playing.
     *
     * Switching a game on does more than add it to the charts. It joins the
     * rail, the catalog and the sitemap, and `gamemonitoring:sync` starts
     * pulling its server list — which for something like ARK is sixty-eight
     * thousand rows. Hence `--dry-run`, and hence the summary at the end
     * saying what else is now in scope.
     */
    public function handle(): int
    {
        $hide = (bool) $this->option('hide');
        $write = ! $this->option('dry-run');

        $games = $this->matches($hide);

        if ($games === null) {
            return self::FAILURE;
        }

        if ($games->isEmpty()) {
            $this->info($hide ? 'No games to hide.' : 'No games to switch on — nothing matched.');

            return self::SUCCESS;
        }

        foreach ($games as $game) {
            $this->line(sprintf(
                '  %-34s %10s players%s',
                $game->slug,
                number_format((int) $game->steam_players_online),
                $game->steam_chart_rank ? '  #'.$game->steam_chart_rank.' on Steam' : '',
            ));
        }

        $this->newLine();

        if (! $write) {
            $this->warn(sprintf(
                'Dry run: %d game(s) would be switched %s.',
                $games->count(),
                $hide ? 'off' : 'on',
            ));

            return self::SUCCESS;
        }

        Game::query()->whereIn('id', $games->pluck('id'))->update(['is_active' => ! $hide]);

        $this->info(sprintf('%d game(s) switched %s.', $games->count(), $hide ? 'off' : 'on'));

        if (! $hide) {
            $this->newLine();
            $this->line('  They are now in the rail, the catalog, the sitemap and the charts.');
            $this->line('  Two things worth doing next:');
            $this->line('    php artisan games:artwork    covers and icons, by app id, from Steam');
            $this->line('    php artisan gamemonitoring:sync --dry-run    see how many servers they would add');
        }

        return self::SUCCESS;
    }

    /**
     * The games the flags name, busiest first.
     *
     * At least one filter is required and `--all` is one of them: a bare
     * `games:activate` that switched on three hundred untouched game pages
     * would be a single word away from a thin-content problem, and that is not
     * a thing to do by accident.
     *
     * @return Collection<int, Game>|null null when the
     *                                    flags do not say
     *                                    what to act on
     */
    private function matches(bool $hide)
    {
        $slug = $this->option('game');
        $minPlayers = $this->option('min-players');
        $charted = (bool) $this->option('charted');
        $all = (bool) $this->option('all');

        if (! $slug && $minPlayers === null && ! $charted && ! $all) {
            $this->error('Say which games: --game=slug, --min-players=N, --charted, or --all.');
            $this->line('  Everything with a Steam id and a thousand players:');
            $this->line('    php artisan games:activate --min-players=1000 --dry-run');

            return null;
        }

        $query = Game::query()
            ->where('is_active', $hide)
            ->whereNotNull('steam_appid');

        if ($slug) {
            $query->where('slug', $slug);
        }

        // A player count only exists once the collector has read one, and a
        // game it has never reached has no business being judged by a number
        // nobody took.
        if ($minPlayers !== null) {
            $query->whereNotNull('steam_stats_synced_at')
                ->where('steam_players_online', '>=', (int) $minPlayers);
        }

        if ($charted) {
            $query->whereNotNull('steam_chart_rank');
        }

        $query->orderByDesc('steam_players_online');

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        return $query->get();
    }
}
