<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerDailyStat;
use App\Models\ServerStat;
use Database\Seeders\CountrySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RollupServerStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CountrySeeder::class, GameSeeder::class]);
    }

    public function test_it_folds_raw_samples_into_daily_stats(): void
    {
        $server = Server::factory()->create();
        $day = now()->subDay()->startOfDay();

        // 10 samples, 2 of them offline → 80% uptime, players 10..90 while online.
        $samples = [];
        for ($i = 0; $i < 10; $i++) {
            $online = $i >= 2;
            $samples[] = [
                'server_id' => $server->id,
                'recorded_at' => $day->copy()->addMinutes($i * 5),
                'is_online' => $online,
                'players_online' => $online ? ($i + 1) * 10 : 0,
                'players_max' => 100,
            ];
        }
        ServerStat::insert($samples);

        $this->artisan('stats:rollup', ['--date' => $day->toDateString(), '--prune-days' => 0])
            ->assertSuccessful();

        $daily = ServerDailyStat::where('server_id', $server->id)->firstOrFail();

        $this->assertSame($day->toDateString(), $daily->date->toDateString());
        $this->assertSame(10, $daily->samples_count);
        $this->assertSame(8, $daily->online_samples_count);
        $this->assertSame('80.00', $daily->uptime_percent);
        $this->assertSame(0, $daily->players_min);
        $this->assertSame(100, $daily->players_peak);
        $this->assertSame('52.00', $daily->players_avg); // (0+0+30+40+…+100)/10 = 520/10
        $this->assertSame('80.00', $server->fresh()->uptime_percent);
    }

    public function test_rerunning_the_rollup_updates_instead_of_duplicating(): void
    {
        $server = Server::factory()->create();
        $day = now()->subDay()->startOfDay();

        ServerStat::insert([[
            'server_id' => $server->id,
            'recorded_at' => $day->copy()->addHour(),
            'is_online' => true,
            'players_online' => 10,
            'players_max' => 100,
        ]]);

        $args = ['--date' => $day->toDateString(), '--prune-days' => 0];
        $this->artisan('stats:rollup', $args)->assertSuccessful();

        // A later sample arrives for the same day, then we roll up again.
        ServerStat::insert([[
            'server_id' => $server->id,
            'recorded_at' => $day->copy()->addHours(2),
            'is_online' => false,
            'players_online' => 0,
            'players_max' => 100,
        ]]);
        $this->artisan('stats:rollup', $args)->assertSuccessful();

        $this->assertSame(1, ServerDailyStat::where('server_id', $server->id)->count());

        $daily = ServerDailyStat::where('server_id', $server->id)->firstOrFail();
        $this->assertSame(2, $daily->samples_count);
        $this->assertSame('50.00', $daily->uptime_percent);
    }

    public function test_it_prunes_raw_samples_past_the_retention_window(): void
    {
        $server = Server::factory()->create();

        ServerStat::insert([
            [
                'server_id' => $server->id,
                'recorded_at' => now()->subDays(20),
                'is_online' => true,
                'players_online' => 5,
                'players_max' => 100,
            ],
            [
                'server_id' => $server->id,
                'recorded_at' => now()->subHour(),
                'is_online' => true,
                'players_online' => 7,
                'players_max' => 100,
            ],
        ]);

        $this->artisan('stats:rollup', ['--prune-days' => 14])->assertSuccessful();

        $this->assertSame(1, ServerStat::where('server_id', $server->id)->count());
        // The pruned day still has its rollup — history survives sample deletion.
        $this->assertDatabaseHas('server_daily_stats', ['server_id' => $server->id]);
    }
}
