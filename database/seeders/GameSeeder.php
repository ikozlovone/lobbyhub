<?php

namespace Database\Seeders;

use App\Enums\QueryProtocol;
use App\Models\Game;
use Illuminate\Database\Seeder;

class GameSeeder extends Seeder
{
    /**
     * The first three games of the catalog. Idempotent: safe to re-run.
     */
    public function run(): void
    {
        foreach ($this->games() as $data) {
            $modes = $data['modes'];
            $versions = $data['versions'];
            unset($data['modes'], $data['versions']);

            $game = Game::updateOrCreate(['slug' => $data['slug']], $data);

            foreach ($modes as $i => [$slug, $name]) {
                $game->modes()->updateOrCreate(
                    ['slug' => $slug],
                    ['name' => $name, 'sort_order' => $i],
                );
            }

            foreach ($versions as $i => [$slug, $name]) {
                $game->versions()->updateOrCreate(
                    ['slug' => $slug],
                    ['name' => $name, 'sort_order' => $i],
                );
            }
        }
    }

    private function games(): array
    {
        return [
            [
                'slug' => 'minecraft',
                'name' => 'Minecraft',
                'short_name' => 'MC',
                'aliases' => ['mc', 'майнкрафт', 'minecraft java'],
                'query_protocol' => QueryProtocol::Minecraft,
                'default_port' => 25565,
                'default_query_port' => null,
                'sort_order' => 10,
                'has_versions' => true,
                'accent_color' => '#4C9A2A',
                'meta_title' => 'Minecraft servers — monitoring and top list',
                'meta_description' => 'Minecraft server list with live player counts, uptime history, votes and reviews.',
                'modes' => [
                    ['survival', 'Survival'],
                    ['skyblock', 'SkyBlock'],
                    ['anarchy', 'Anarchy'],
                    ['creative', 'Creative'],
                    ['minigames', 'Minigames'],
                    ['prison', 'Prison'],
                    ['factions', 'Factions'],
                    ['pvp', 'PvP'],
                ],
                'versions' => [
                    ['1-21', '1.21'],
                    ['1-20', '1.20'],
                    ['1-19', '1.19'],
                    ['1-18', '1.18'],
                    ['1-17', '1.17'],
                    ['1-16', '1.16'],
                    ['1-12', '1.12'],
                ],
            ],
            [
                'slug' => 'rust',
                'name' => 'Rust',
                'short_name' => null,
                'aliases' => ['раст'],
                'query_protocol' => QueryProtocol::Source,
                'default_port' => 28015,
                'default_query_port' => null, // Rust answers A2S on the game port by default
                'sort_order' => 20,
                'has_versions' => false,
                'accent_color' => '#CD412B',
                'meta_title' => 'Rust servers — monitoring and top list',
                'meta_description' => 'Rust server list with live player counts, wipe info, uptime history and votes.',
                'modes' => [
                    ['vanilla', 'Vanilla'],
                    ['modded', 'Modded'],
                    ['pve', 'PvE'],
                    ['roleplay', 'Roleplay'],
                    ['hardcore', 'Hardcore'],
                    ['softcore', 'Softcore'],
                    ['build', 'Build / Creative'],
                ],
                'versions' => [],
            ],
            [
                'slug' => 'fivem',
                'name' => 'FiveM',
                'short_name' => 'GTA RP',
                'aliases' => ['gta 5 rp', 'gta online rp', 'фивем', 'cfx'],
                'query_protocol' => QueryProtocol::FiveM,
                'default_port' => 30120,
                'default_query_port' => null, // HTTP endpoints live on the game port
                'sort_order' => 30,
                'has_versions' => false,
                'accent_color' => '#F0A30A',
                'meta_title' => 'FiveM servers — GTA 5 roleplay monitoring',
                'meta_description' => 'FiveM server list with live player counts, roleplay modes, uptime history and votes.',
                'modes' => [
                    ['roleplay', 'Roleplay'],
                    ['drift', 'Drift'],
                    ['racing', 'Racing'],
                    ['freeroam', 'Freeroam'],
                    ['deathmatch', 'Deathmatch'],
                    ['zombie', 'Zombie / Survival'],
                ],
                'versions' => [],
            ],
        ];
    }
}
