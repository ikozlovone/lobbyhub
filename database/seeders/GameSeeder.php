<?php

namespace Database\Seeders;

use App\Enums\QueryProtocol;
use App\Models\Game;
use Illuminate\Database\Seeder;

class GameSeeder extends Seeder
{
    /**
     * The catalog.
     *
     * Every game here is one our existing drivers can already query — the Source
     * driver covers the whole Steam A2S family, so adding those games costs
     * nothing but a row. Games we cannot monitor are deliberately absent: an
     * empty landing page is a liability, not coverage.
     *
     * `default_query_port` is only a hint for the submission form. A server's own
     * `query_port` wins, and falls back to its game port — so a wrong hint here
     * cannot silently misdirect monitoring.
     *
     * Idempotent: safe to re-run.
     */
    public function run(): void
    {
        foreach ($this->games() as $data) {
            $modes = $data['modes'];
            $versions = $data['versions'] ?? [];
            unset($data['modes'], $data['versions']);

            $game = Game::updateOrCreate(['slug' => $data['slug']], $data);

            foreach ($modes as $i => [$slug, $name]) {
                $game->modes()->updateOrCreate(['slug' => $slug], ['name' => $name, 'sort_order' => $i]);
            }

            foreach ($versions as $i => [$slug, $name]) {
                $game->versions()->updateOrCreate(['slug' => $slug], ['name' => $name, 'sort_order' => $i]);
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
                // Not sold on Steam, so it has no app id and needs its own artwork.
                'steam_appid' => null,
                'query_protocol' => QueryProtocol::Minecraft,
                'default_port' => 25565,
                'default_query_port' => null,
                'sort_order' => 10,
                'has_versions' => true,
                'accent_color' => '#4C9A2A',
                'meta_title' => 'Minecraft servers — monitoring and top list',
                'meta_description' => 'Minecraft server list with live player counts, uptime history, votes and reviews.',
                'modes' => [
                    ['survival', 'Survival'], ['skyblock', 'SkyBlock'], ['anarchy', 'Anarchy'],
                    ['creative', 'Creative'], ['minigames', 'Minigames'], ['prison', 'Prison'],
                    ['factions', 'Factions'], ['pvp', 'PvP'],
                ],
                'versions' => [
                    ['1-21', '1.21'], ['1-20', '1.20'], ['1-19', '1.19'], ['1-18', '1.18'],
                    ['1-17', '1.17'], ['1-16', '1.16'], ['1-12', '1.12'],
                ],
            ],
            [
                'slug' => 'rust',
                'name' => 'Rust',
                'aliases' => ['раст'],
                'steam_appid' => 252490,
                'query_protocol' => QueryProtocol::Source,
                'default_port' => 28015,
                'default_query_port' => null,
                'sort_order' => 20,
                'accent_color' => '#CD412B',
                'meta_title' => 'Rust servers — monitoring and top list',
                'meta_description' => 'Rust server list with live player counts, wipe dates, uptime history and votes.',
                'modes' => [
                    ['vanilla', 'Vanilla'], ['modded', 'Modded'], ['pve', 'PvE'],
                    ['roleplay', 'Roleplay'], ['hardcore', 'Hardcore'], ['softcore', 'Softcore'],
                    ['build', 'Build / Creative'],
                ],
            ],
            [
                'slug' => 'fivem',
                'name' => 'FiveM',
                'short_name' => 'GTA RP',
                'aliases' => ['gta 5 rp', 'gta online rp', 'фивем', 'cfx'],
                // FiveM itself is not a Steam product; GTA V's art represents it.
                'steam_appid' => 271590,
                'query_protocol' => QueryProtocol::FiveM,
                'default_port' => 30120,
                'default_query_port' => null,
                'sort_order' => 30,
                'accent_color' => '#F0A30A',
                'meta_title' => 'FiveM servers — GTA 5 roleplay monitoring',
                'meta_description' => 'FiveM server list with live player counts, roleplay modes, uptime history and votes.',
                'modes' => [
                    ['roleplay', 'Roleplay'], ['drift', 'Drift'], ['racing', 'Racing'],
                    ['freeroam', 'Freeroam'], ['deathmatch', 'Deathmatch'], ['zombie', 'Zombie / Survival'],
                ],
            ],

            // --- Steam A2S family: already covered by the Source driver ---

            $this->source('ark-survival-evolved', 'ARK: Survival Evolved', 346110, 7777, 27015, 40, '#1B7FA8', [
                ['pve', 'PvE'], ['pvp', 'PvP'], ['modded', 'Modded'], ['roleplay', 'Roleplay'],
            ], ['ark', 'арк']),

            $this->source('7-days-to-die', '7 Days to Die', 251570, 26900, null, 50, '#8B4A2B', [
                ['pve', 'PvE'], ['pvp', 'PvP'], ['modded', 'Modded'],
            ], ['7dtd', '7 days']),

            $this->source('project-zomboid', 'Project Zomboid', 108600, 16261, null, 60, '#5B7A3A', [
                ['pve', 'PvE'], ['pvp', 'PvP'], ['roleplay', 'Roleplay'], ['modded', 'Modded'],
            ], ['pz', 'зомбоид']),

            $this->source('dayz', 'DayZ', 221100, 2302, 27016, 70, '#6B7A5A', [
                ['vanilla', 'Vanilla'], ['modded', 'Modded'], ['roleplay', 'Roleplay'], ['hardcore', 'Hardcore'],
            ], ['дейзи']),

            $this->source('counter-strike-2', 'Counter-Strike 2', 730, 27015, null, 80, '#DE9B35', [
                ['competitive', 'Competitive'], ['deathmatch', 'Deathmatch'], ['surf', 'Surf'],
                ['bhop', 'Bhop'], ['retake', 'Retake'], ['zombie', 'Zombie Escape'],
            ], ['cs2', 'кс2', 'counter strike']),

            $this->source('garrys-mod', "Garry's Mod", 4000, 27015, null, 90, '#3B7CB8', [
                ['darkrp', 'DarkRP'], ['ttt', 'Trouble in Terrorist Town'], ['sandbox', 'Sandbox'],
                ['prophunt', 'Prop Hunt'], ['murder', 'Murder'],
            ], ['gmod', 'гмод']),

            $this->source('team-fortress-2', 'Team Fortress 2', 440, 27015, null, 100, '#B8503B', [
                ['casual', 'Casual'], ['surf', 'Surf'], ['jump', 'Jump'], ['trade', 'Trade'],
            ], ['tf2']),

            $this->source('squad', 'Squad', 393380, 7787, 27165, 110, '#4A6B3A', [
                ['vanilla', 'Vanilla'], ['modded', 'Modded'], ['training', 'Training'],
            ]),

            $this->source('unturned', 'Unturned', 304930, 27015, 27016, 120, '#6BA83B', [
                ['pve', 'PvE'], ['pvp', 'PvP'], ['roleplay', 'Roleplay'], ['modded', 'Modded'],
            ]),

            $this->source('valheim', 'Valheim', 892970, 2456, 2457, 130, '#3B6B8B', [
                ['pve', 'PvE'], ['pvp', 'PvP'], ['modded', 'Modded'],
            ], ['вальхейм']),

            $this->source('conan-exiles', 'Conan Exiles', 440900, 7777, 27015, 140, '#A85B2B', [
                ['pve', 'PvE'], ['pve-c', 'PvE-Conflict'], ['pvp', 'PvP'], ['roleplay', 'Roleplay'],
            ]),

            $this->source('arma-3', 'Arma 3', 107410, 2302, 2303, 150, '#6B6B4A', [
                ['altis-life', 'Altis Life'], ['king-of-the-hill', 'King of the Hill'],
                ['wasteland', 'Wasteland'], ['milsim', 'Milsim'],
            ], ['арма']),

            $this->source('palworld', 'Palworld', 1623730, 8211, 27015, 160, '#3BA8A8', [
                ['pve', 'PvE'], ['pvp', 'PvP'], ['modded', 'Modded'],
            ], ['палворлд']),

            $this->source('v-rising', 'V Rising', 1604030, 9876, 9877, 170, '#8B2B3B', [
                ['pve', 'PvE'], ['pvp', 'PvP'], ['duo', 'Duo'], ['trio', 'Trio'],
            ]),

            // --- Source and GoldSrc engine: A2S is native to them ---

            $this->source('left-4-dead-2', 'Left 4 Dead 2', 550, 27015, null, 180, '#8B3B1B', [
                ['campaign', 'Campaign'], ['versus', 'Versus'], ['survival', 'Survival'], ['modded', 'Modded'],
            ], ['l4d2']),

            $this->source('counter-strike', 'Counter-Strike 1.6', 10, 27015, null, 190, '#C88A2B', [
                ['classic', 'Classic'], ['deathmatch', 'Deathmatch'], ['zombie', 'Zombie'],
                ['jailbreak', 'Jailbreak'], ['surf', 'Surf'],
            ], ['cs 1.6', 'кс 1.6', 'кс']),

            $this->source('counter-strike-condition-zero', 'Counter-Strike: Condition Zero', 80, 27015, null, 200, '#B87A2B', [
                ['classic', 'Classic'], ['deathmatch', 'Deathmatch'], ['zombie', 'Zombie'],
            ], ['czero', 'cs cz']),

            $this->source('sven-co-op', 'Sven Co-op', 225840, 27015, null, 210, '#3B8B6B', [
                ['coop', 'Co-op'], ['custom-maps', 'Custom Maps'],
            ]),

            $this->source('no-more-room-in-hell', 'No More Room in Hell', 224260, 27015, null, 220, '#6B3B3B', [
                ['objective', 'Objective'], ['survival', 'Survival'],
            ], ['nmrih']),

            // --- Survival and sandbox titles that answer A2S ---

            $this->source('scum', 'SCUM', 513710, 7042, null, 230, '#7A6B3B', [
                ['pve', 'PvE'], ['pvp', 'PvP'], ['roleplay', 'Roleplay'], ['modded', 'Modded'],
            ]),

            $this->source('enshrouded', 'Enshrouded', 1203620, 15636, 15637, 240, '#5B4A8B', [
                ['pve', 'PvE'], ['coop', 'Co-op'], ['modded', 'Modded'],
            ]),

            $this->source('icarus', 'Icarus', 1149460, 17777, null, 250, '#3B6B8B', [
                ['open-world', 'Open World'], ['missions', 'Missions'], ['pve', 'PvE'],
            ]),

            $this->source('soulmask', 'Soulmask', 2646460, 8777, null, 260, '#8B6B3B', [
                ['pve', 'PvE'], ['pvp', 'PvP'], ['roleplay', 'Roleplay'],
            ]),

            $this->source('space-engineers', 'Space Engineers', 244850, 27016, null, 270, '#4A6B8B', [
                ['survival', 'Survival'], ['creative', 'Creative'], ['pvp', 'PvP'], ['modded', 'Modded'],
            ], ['se']),
        ];
    }

    /**
     * A Steam game monitored over A2S. Everything these share is filled in here
     * so the list above stays readable.
     */
    private function source(
        string $slug,
        string $name,
        int $appId,
        int $port,
        ?int $queryPort,
        int $order,
        string $colour,
        array $modes,
        array $aliases = [],
    ): array {
        return [
            'slug' => $slug,
            'name' => $name,
            'aliases' => $aliases === [] ? null : $aliases,
            'steam_appid' => $appId,
            'query_protocol' => QueryProtocol::Source,
            'default_port' => $port,
            'default_query_port' => $queryPort,
            'sort_order' => $order,
            'has_versions' => false,
            'accent_color' => $colour,
            'meta_title' => "{$name} servers — monitoring and top list",
            'meta_description' => "{$name} server list with live player counts, uptime history and votes.",
            'modes' => $modes,
            'versions' => [],
        ];
    }
}
