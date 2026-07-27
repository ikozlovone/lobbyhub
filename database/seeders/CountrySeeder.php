<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    /**
     * Countries that actually host game servers. Idempotent: safe to re-run.
     * Geo lookup maps a resolved IP onto one of these by ISO code.
     */
    public function run(): void
    {
        foreach ($this->countries() as [$code, $name, $slug, $continent]) {
            Country::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'slug' => $slug, 'continent' => $continent],
            );
        }
    }

    private function countries(): array
    {
        return [
            ['DE', 'Germany', 'germany', 'Europe'],
            ['NL', 'Netherlands', 'netherlands', 'Europe'],
            ['FR', 'France', 'france', 'Europe'],
            ['GB', 'United Kingdom', 'united-kingdom', 'Europe'],
            ['PL', 'Poland', 'poland', 'Europe'],
            ['RU', 'Russia', 'russia', 'Europe'],
            ['UA', 'Ukraine', 'ukraine', 'Europe'],
            ['FI', 'Finland', 'finland', 'Europe'],
            ['SE', 'Sweden', 'sweden', 'Europe'],
            ['NO', 'Norway', 'norway', 'Europe'],
            ['DK', 'Denmark', 'denmark', 'Europe'],
            ['ES', 'Spain', 'spain', 'Europe'],
            ['IT', 'Italy', 'italy', 'Europe'],
            ['PT', 'Portugal', 'portugal', 'Europe'],
            ['CH', 'Switzerland', 'switzerland', 'Europe'],
            ['AT', 'Austria', 'austria', 'Europe'],
            ['CZ', 'Czechia', 'czechia', 'Europe'],
            ['RO', 'Romania', 'romania', 'Europe'],
            ['LT', 'Lithuania', 'lithuania', 'Europe'],
            ['LV', 'Latvia', 'latvia', 'Europe'],
            ['EE', 'Estonia', 'estonia', 'Europe'],
            ['TR', 'Turkey', 'turkey', 'Asia'],

            ['US', 'United States', 'united-states', 'North America'],
            ['CA', 'Canada', 'canada', 'North America'],
            ['MX', 'Mexico', 'mexico', 'North America'],

            ['BR', 'Brazil', 'brazil', 'South America'],
            ['AR', 'Argentina', 'argentina', 'South America'],
            ['CL', 'Chile', 'chile', 'South America'],

            ['SG', 'Singapore', 'singapore', 'Asia'],
            ['JP', 'Japan', 'japan', 'Asia'],
            ['KR', 'South Korea', 'south-korea', 'Asia'],
            ['HK', 'Hong Kong', 'hong-kong', 'Asia'],
            ['IN', 'India', 'india', 'Asia'],
            ['ID', 'Indonesia', 'indonesia', 'Asia'],
            ['VN', 'Vietnam', 'vietnam', 'Asia'],
            ['TH', 'Thailand', 'thailand', 'Asia'],
            ['KZ', 'Kazakhstan', 'kazakhstan', 'Asia'],
            ['AE', 'United Arab Emirates', 'united-arab-emirates', 'Asia'],
            ['IL', 'Israel', 'israel', 'Asia'],

            ['AU', 'Australia', 'australia', 'Oceania'],
            ['NZ', 'New Zealand', 'new-zealand', 'Oceania'],

            ['ZA', 'South Africa', 'south-africa', 'Africa'],
        ];
    }
}
