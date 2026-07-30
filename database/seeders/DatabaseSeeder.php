<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // An account to develop against, and only that. Two reasons it does not
        // run on a deployed machine: the factory behind it needs Faker, which is
        // a dev dependency and therefore absent after `composer install
        // --no-dev`; and a live catalog has no business holding an account at a
        // well-known address that anyone can ask a sign-in code for.
        if (app()->environment('local')) {
            User::firstOrCreate(
                ['email' => 'test@example.com'],
                User::factory()->raw(['email' => 'test@example.com', 'name' => 'Test User']),
            );
        }

        $this->call([
            CountrySeeder::class,
            GameSeeder::class,
        ]);
    }
}
