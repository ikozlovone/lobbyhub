<?php

namespace Database\Factories;

use App\Enums\ServerStatus;
use App\Models\ServerState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerState>
 */
class ServerStateFactory extends Factory
{
    protected $model = ServerState::class;

    public function definition(): array
    {
        $max = fake()->randomElement([50, 100, 128, 200, 500]);

        return [
            // server_id and game_id must be provided by the caller — a state
            // row without them cannot be routed to a partition.
            'status' => ServerStatus::Online,
            'players_online' => fake()->numberBetween(0, $max),
            'players_max' => $max,
            'players_queued' => 0,
            'last_queried_at' => now(),
            'last_online_at' => now(),
            'next_query_at' => now()->addMinutes(5),
            'failed_queries_count' => 0,
            'uptime_percent' => fake()->randomFloat(2, 90, 100),
        ];
    }

    public function offline(): static
    {
        return $this->state(fn () => [
            'status' => ServerStatus::Offline,
            'players_online' => 0,
            'last_online_at' => now()->subDays(3),
            'last_offline_at' => now(),
            'failed_queries_count' => fake()->numberBetween(1, 20),
        ]);
    }

    public function unknown(): static
    {
        return $this->state(fn () => [
            'status' => ServerStatus::Unknown,
            'players_online' => 0,
            'last_queried_at' => null,
            'last_online_at' => null,
        ]);
    }
}
