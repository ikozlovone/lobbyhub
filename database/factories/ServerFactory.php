<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\Game;
use App\Models\Server;
use App\Models\ServerState;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /**
     * Attributes that used to live on `servers` and now live on `server_states`.
     * Anything the caller passes with one of these names is routed to state
     * in `configure()`, so a pre-split call site keeps working.
     */
    private const HOT_FIELDS = [
        'status',
        'players_online',
        'players_max',
        'players_queued',
        'bots',
        'vac_enabled',
        'map',
        'reported_version',
        'motd',
        'wiped_at',
        'steam_id',
        'game_port',
        'last_queried_at',
        'last_online_at',
        'last_offline_at',
        'next_query_at',
        'failed_queries_count',
        'steam_seen_at',
        'uptime_percent',
    ];

    public function definition(): array
    {
        // Games come from GameSeeder, not a factory — seed them first.
        $game = Game::query()->inRandomOrder()->firstOrFail();
        $name = fake()->unique()->domainWord().' '.fake()->randomElement(['Network', 'Project', 'Community', 'Hub']);

        return [
            'game_id' => $game->id,
            'host' => fake()->unique()->domainName(),
            'port' => $game->default_port,
            'ip_address' => fake()->ipv4(),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'name' => $name,
            'description' => fake()->sentence(12),
            'country_id' => Country::query()->inRandomOrder()->value('id'),
            'votes_count' => fake()->numberBetween(0, 5000),
        ];
    }

    /**
     * Every server gets a state row. Anything the caller passed with a hot
     * field name is routed here — so `Server::factory()->create(['status' =>
     * 'offline', 'players_online' => 5])` still means what it used to, even
     * though those columns no longer belong to `servers`.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Server $server) {
            $hot = array_intersect_key(
                $server->getAttributes(),
                array_flip(self::HOT_FIELDS),
            );

            $state = ServerState::factory()
                ->state(fn () => $hot)
                ->create([
                    'server_id' => $server->id,
                    'game_id' => $server->game_id,
                ]);

            // Pin the relation so `$server->state->...` reads it without a
            // second query. The magic accessors on Server take the same path.
            $server->setRelation('state', $state);
        });
    }

    public function offline(): static
    {
        return $this->state(fn () => [
            'status' => 'offline',
            'players_online' => 0,
            'last_online_at' => now()->subDays(3),
            'failed_queries_count' => fake()->numberBetween(1, 20),
        ]);
    }

    public function unknown(): static
    {
        return $this->state(fn () => [
            'status' => 'unknown',
            'players_online' => 0,
            'last_queried_at' => null,
            'last_online_at' => null,
        ]);
    }

    public function promoted(): static
    {
        return $this->state(fn () => [
            'promoted_until' => now()->addMonth(),
        ]);
    }
}
