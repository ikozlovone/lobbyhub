<?php

namespace Database\Factories;

use App\Enums\ServerStatus;
use App\Models\Country;
use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\Server>
 */
class ServerFactory extends Factory
{
    public function definition(): array
    {
        // Games come from GameSeeder, not a factory — seed them first.
        $game = Game::query()->inRandomOrder()->firstOrFail();
        $name = fake()->unique()->domainWord().' '.fake()->randomElement(['Network', 'Project', 'Community', 'Hub']);
        $max = fake()->randomElement([50, 100, 128, 200, 500]);

        return [
            'game_id' => $game->id,
            'host' => fake()->unique()->domainName(),
            'port' => $game->default_port,
            'ip_address' => fake()->ipv4(),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'name' => $name,
            'description' => fake()->sentence(12),
            'country_id' => Country::query()->inRandomOrder()->value('id'),
            'status' => ServerStatus::Online,
            'players_online' => fake()->numberBetween(0, $max),
            'players_max' => $max,
            'last_queried_at' => now(),
            'last_online_at' => now(),
            'uptime_percent' => fake()->randomFloat(2, 90, 100),
            'votes_count' => fake()->numberBetween(0, 5000),
        ];
    }

    public function offline(): static
    {
        return $this->state(fn () => [
            'status' => ServerStatus::Offline,
            'players_online' => 0,
            'last_online_at' => now()->subDays(3),
            'failed_queries_count' => fake()->numberBetween(1, 20),
        ]);
    }

    public function promoted(): static
    {
        return $this->state(fn () => [
            'promoted_until' => now()->addMonth(),
        ]);
    }
}
