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
        $links = $this->links();

        foreach ($this->games() as $data) {
            $modes = $data['modes'];
            $versions = $data['versions'] ?? [];
            unset($data['modes'], $data['versions']);

            // Absent rather than null for a game with none: the column is then
            // left alone, and links added in the admin survive a re-seed.
            if (isset($links[$data['slug']])) {
                $data['links'] = $links[$data['slug']];
            }

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

            $this->source('counter-strike-source', 'Counter-Strike: Source', 240, 27015, null, 185, '#C8823B', [
                ['classic', 'Classic'], ['deathmatch', 'Deathmatch'], ['zombie', 'Zombie'],
                ['jailbreak', 'Jailbreak'], ['surf', 'Surf'], ['bhop', 'Bhop'],
            ], ['css', 'кс сорс']),

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

            // --- The ARK engine family and its neighbours ---

            $this->source('ark-survival-ascended', 'ARK: Survival Ascended', 2399830, 7777, 27015, 280, '#2B8BA8', [
                ['pve', 'PvE'], ['pvp', 'PvP'], ['modded', 'Modded'], ['roleplay', 'Roleplay'],
            ], ['asa', 'ark sa', 'арк асцендед']),

            $this->source('the-front', 'The Front', 2285150, 7777, 7779, 290, '#7A5B4A', [
                ['pve', 'PvE'], ['pvp', 'PvP'], ['modded', 'Modded'],
            ]),

            $this->source('renown', 'Renown', 1488310, 7777, 27015, 300, '#7A5B3B', [
                ['pve', 'PvE'], ['pvp', 'PvP'], ['roleplay', 'Roleplay'], ['modded', 'Modded'],
            ]),

            $this->source('myth-of-empires', 'Myth of Empires', 1371580, 12888, 27015, 310, '#8B6B4A', [
                ['pve', 'PvE'], ['pvp', 'PvP'], ['roleplay', 'Roleplay'],
            ], ['moe']),

            $this->source('pixark', 'PixARK', 593600, 7777, 27015, 320, '#6BA85B', [
                ['pve', 'PvE'], ['pvp', 'PvP'], ['creative', 'Creative'], ['modded', 'Modded'],
            ]),

            // ATLAS runs a grid of servers, one port pair per cell; these are the
            // first cell's, which is what a single-server host publishes.
            $this->source('atlas', 'ATLAS', 834910, 5761, 57561, 330, '#3B6B8B', [
                ['pve', 'PvE'], ['pvp', 'PvP'], ['roleplay', 'Roleplay'],
            ], ['атлас']),

            $this->source('dark-and-light', 'Dark and Light', 529180, 7777, 27015, 340, '#6B4A8B', [
                ['pve', 'PvE'], ['pvp', 'PvP'], ['roleplay', 'Roleplay'],
            ], ['dnl']),

            // --- Milsim and tactical shooters: Unreal on top of A2S ---

            $this->source('hell-let-loose', 'Hell Let Loose', 686810, 7777, 27015, 350, '#6B5B3B', [
                ['warfare', 'Warfare'], ['offensive', 'Offensive'], ['skirmish', 'Skirmish'],
            ], ['hll', 'хлл']),

            // Sandstorm listens on 27102 and answers queries on 27131 — one of the
            // few games where the two ports are nowhere near each other.
            $this->source('insurgency-sandstorm', 'Insurgency: Sandstorm', 581320, 27102, 27131, 360, '#A8823B', [
                ['push', 'Push'], ['firefight', 'Firefight'], ['skirmish', 'Skirmish'],
                ['checkpoint', 'Co-op Checkpoint'], ['competitive', 'Competitive'], ['modded', 'Modded'],
            ], ['sandstorm', 'сандшторм']),

            $this->source('mordhau', 'MORDHAU', 629760, 7777, 27015, 370, '#8B7A4A', [
                ['frontline', 'Frontline'], ['invasion', 'Invasion'], ['deathmatch', 'Deathmatch'],
                ['horde', 'Horde'], ['duel', 'Duel'], ['modded', 'Modded'],
            ], ['мордхау']),

            $this->source('arma-reforger', 'Arma Reforger', 1874880, 2001, 17777, 380, '#5B6B5B', [
                ['conflict', 'Conflict'], ['game-master', 'Game Master'],
                ['combat-ops', 'Combat Ops'], ['milsim', 'Milsim'], ['modded', 'Modded'],
            ], ['reforger', 'рефорджер']),

            $this->source('squad-44', 'Squad 44', 736220, 7787, 27165, 390, '#4A5B6B', [
                ['advance-and-secure', 'Advance and Secure'], ['offensive', 'Offensive'],
                ['armoured', 'Armoured'], ['modded', 'Modded'],
            ], ['post scriptum', 'ps']),

            $this->source('rising-storm-2-vietnam', 'Rising Storm 2: Vietnam', 418460, 7777, 27015, 400, '#5B6B3B', [
                ['territories', 'Territories'], ['supremacy', 'Supremacy'],
                ['campaign', 'Campaign'], ['skirmish', 'Skirmish'],
            ], ['rs2', 'вьетнам']),

            $this->source('insurgency', 'Insurgency', 222880, 27015, null, 410, '#8B6B2B', [
                ['push', 'Push'], ['firefight', 'Firefight'], ['coop', 'Co-op'], ['hunt', 'Hunt'],
            ], ['insurgency 2014']),

            $this->source('arma-2', 'Arma 2', 33900, 2302, 2303, 420, '#6B5B4A', [
                ['dayz-mod', 'DayZ Mod'], ['wasteland', 'Wasteland'],
                ['domination', 'Domination'], ['milsim', 'Milsim'],
            ], ['arma 2 oa', 'арма 2']),

            $this->source('beyond-the-wire', 'Beyond The Wire', 1058650, 7787, 27165, 430, '#6B6B5B', [
                ['attrition', 'Attrition'], ['frontline', 'Frontline'], ['skirmish', 'Skirmish'],
            ], ['btw']),

            // Battalion 1944 kept its servers and lost its name: it is free-to-play
            // BATTALION: Legacy on Steam now, so the old name is only an alias.
            $this->source('battalion-legacy', 'BATTALION: Legacy', 489940, 7777, 7780, 440, '#6B6B7A', [
                ['arcade', 'Arcade'], ['wartide', 'Wartide'], ['competitive', 'Competitive'],
                ['deathmatch', 'Deathmatch'],
            ], ['battalion 1944', 'btl']),

            // Early access since April 2026, so it has one mode and little else to
            // say about it yet.
            $this->source('83', "'83", 1059220, 7777, 27015, 450, '#6B7A5B', [
                ['pvp', 'PvP'],
            ], ['83', 'eighty three']),
        ];
    }

    /**
     * Where each game lives outside this site, shown at the top of its page.
     *
     * Three rules, and they are why some games have two links and some have one:
     *
     *  - First-party first. The studio's own site, and its own server
     *    documentation — Valve's developer wiki, Facepunch's, Bohemia's,
     *    Pocketpair's, Cfx.re's. A hosting company's tutorial is somebody's
     *    marketing; a fan wiki is somebody's spare time.
     *  - The Steam page when there is nothing else. Several of these games never
     *    had a site, or had one that has since lapsed — thefrontgame.com and
     *    renownthegame.com resolve to nothing, pixark.com is a domain for sale.
     *    A store page is at least the game, published by the people who made it.
     *  - Nothing commercial. The competitor's version of this block sells server
     *    hosting and a donation platform, referral tags and all. Who to send our
     *    visitors to is a decision worth making on purpose, in the admin.
     *
     * Every address here was fetched before it was written down. Bohemia's wiki
     * refuses automated requests, so those four are the URLs their own pages are
     * indexed under; the rest answered 200.
     *
     * @return array<string, list<array{name: string, url: string}>>
     */
    private function links(): array
    {
        return [
            'minecraft' => [
                ['name' => 'Minecraft Official', 'url' => 'https://www.minecraft.net/'],
                ['name' => 'Server download', 'url' => 'https://www.minecraft.net/en-us/download/server'],
            ],
            'rust' => [
                ['name' => 'Rust Official', 'url' => 'https://rust.facepunch.com/'],
                ['name' => 'Server hosting docs', 'url' => 'https://wiki.facepunch.com/rust/Creating-a-server'],
            ],
            'fivem' => [
                ['name' => 'FiveM Official', 'url' => 'https://fivem.net/'],
                ['name' => 'FiveM Docs', 'url' => 'https://docs.fivem.net/'],
                ['name' => 'Server setup guide', 'url' => 'https://docs.fivem.net/docs/server-manual/setting-up-a-server/'],
            ],
            'ark-survival-evolved' => [
                ['name' => 'ARK Official', 'url' => 'https://playark.com/'],
                ['name' => 'Dedicated server setup', 'url' => 'https://ark.wiki.gg/wiki/Dedicated_server_setup'],
            ],
            'ark-survival-ascended' => [
                ['name' => 'ARK Official', 'url' => 'https://survivetheark.com/'],
                ['name' => 'Dedicated server setup', 'url' => 'https://ark.wiki.gg/wiki/Dedicated_server_setup'],
            ],
            '7-days-to-die' => [
                ['name' => '7 Days to Die Official', 'url' => 'https://7daystodie.com/'],
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/251570/'],
            ],
            'project-zomboid' => [
                ['name' => 'Project Zomboid Official', 'url' => 'https://projectzomboid.com/'],
                ['name' => 'Dedicated server guide', 'url' => 'https://pzwiki.net/wiki/Dedicated_server'],
            ],
            'dayz' => [
                ['name' => 'DayZ Official', 'url' => 'https://dayz.com/'],
                ['name' => 'Server configuration', 'url' => 'https://community.bistudio.com/wiki/DayZ:Server_Configuration'],
            ],
            'counter-strike-2' => [
                ['name' => 'Counter-Strike Official', 'url' => 'https://www.counter-strike.net/'],
                ['name' => 'Dedicated server docs', 'url' => 'https://developer.valvesoftware.com/wiki/Counter-Strike_2/Dedicated_Servers'],
            ],
            'garrys-mod' => [
                ['name' => "Garry's Mod Official", 'url' => 'https://gmod.facepunch.com/'],
                ['name' => 'Dedicated server guide', 'url' => 'https://wiki.facepunch.com/gmod/Downloading_a_Dedicated_Server'],
            ],
            'team-fortress-2' => [
                ['name' => 'Team Fortress 2 Official', 'url' => 'https://www.teamfortress.com/'],
                ['name' => 'Dedicated server docs', 'url' => 'https://developer.valvesoftware.com/wiki/Team_Fortress_2_Dedicated_Server'],
            ],
            'squad' => [
                ['name' => 'Squad Official', 'url' => 'https://joinsquad.com/'],
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/393380/'],
            ],
            'unturned' => [
                ['name' => 'Smartly Dressed Games', 'url' => 'https://smartlydressedgames.com/'],
                ['name' => 'Server documentation', 'url' => 'https://docs.smartlydressedgames.com/'],
            ],
            'valheim' => [
                ['name' => 'Valheim Official', 'url' => 'https://www.valheimgame.com/'],
                ['name' => 'Dedicated server guide', 'url' => 'https://www.valheimgame.com/support/a-guide-to-dedicated-servers/'],
            ],
            'conan-exiles' => [
                ['name' => 'Conan Exiles Official', 'url' => 'https://conanexiles.com/'],
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/440900/'],
            ],
            'arma-3' => [
                ['name' => 'Arma 3 Official', 'url' => 'https://arma3.com/'],
                ['name' => 'Dedicated server docs', 'url' => 'https://community.bistudio.com/wiki/Arma_3_Dedicated_Server'],
            ],
            'arma-2' => [
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/33900/'],
                ['name' => 'Dedicated server docs', 'url' => 'https://community.bistudio.com/wiki/Arma_Dedicated_Server'],
            ],
            'arma-reforger' => [
                ['name' => 'Arma Reforger Official', 'url' => 'https://reforger.armaplatform.com/'],
                ['name' => 'Server hosting docs', 'url' => 'https://community.bistudio.com/wiki/Arma_Reforger:Server_Hosting'],
            ],
            'palworld' => [
                ['name' => 'Palworld Official', 'url' => 'https://www.pocketpair.jp/palworld'],
                ['name' => 'Dedicated server guide', 'url' => 'https://tech.palworldgame.com/dedicated-server-guide'],
            ],
            'v-rising' => [
                ['name' => 'V Rising Official', 'url' => 'https://playvrising.com/'],
                ['name' => 'Server instructions', 'url' => 'https://github.com/StunlockStudios/vrising-dedicated-server-instructions'],
            ],
            'left-4-dead-2' => [
                ['name' => 'Left 4 Dead Official', 'url' => 'https://www.l4d.com/'],
                ['name' => 'Dedicated server docs', 'url' => 'https://developer.valvesoftware.com/wiki/Left_4_Dead_2_Dedicated_Server'],
            ],
            'counter-strike-source' => [
                ['name' => 'Counter-Strike Official', 'url' => 'https://www.counter-strike.net/'],
                ['name' => 'Dedicated server docs', 'url' => 'https://developer.valvesoftware.com/wiki/Counter-Strike:_Source_Dedicated_Server'],
            ],
            'counter-strike' => [
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/10/'],
                ['name' => 'Dedicated server docs', 'url' => 'https://developer.valvesoftware.com/wiki/Counter-Strike_Dedicated_Server'],
            ],
            'counter-strike-condition-zero' => [
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/80/'],
                ['name' => 'Dedicated server docs', 'url' => 'https://developer.valvesoftware.com/wiki/Counter-Strike_Dedicated_Server'],
            ],
            'sven-co-op' => [
                ['name' => 'Sven Co-op Official', 'url' => 'https://www.svencoop.com/'],
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/225840/'],
            ],
            'no-more-room-in-hell' => [
                ['name' => 'No More Room in Hell Official', 'url' => 'https://www.nomoreroominhell.com/'],
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/224260/'],
            ],
            'scum' => [
                ['name' => 'SCUM Official', 'url' => 'https://scumgame.com/'],
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/513710/'],
            ],
            'enshrouded' => [
                ['name' => 'Enshrouded Official', 'url' => 'https://enshrouded.com/'],
                ['name' => 'Dedicated server guide', 'url' => 'https://enshrouded.com/dedicated-server'],
            ],
            'icarus' => [
                ['name' => 'Icarus Official', 'url' => 'https://www.surviveicarus.com/'],
                ['name' => 'Dedicated server guide', 'url' => 'https://www.surviveicarus.com/dedicated-servers/'],
            ],
            'soulmask' => [
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/2646460/'],
            ],
            'space-engineers' => [
                ['name' => 'Space Engineers Official', 'url' => 'https://www.spaceengineersgame.com/'],
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/244850/'],
            ],
            'the-front' => [
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/2285150/'],
            ],
            'renown' => [
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/1488310/'],
            ],
            'myth-of-empires' => [
                ['name' => 'Myth of Empires Official', 'url' => 'https://www.mythofempires.com/'],
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/1371580/'],
            ],
            'pixark' => [
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/593600/'],
            ],
            'atlas' => [
                ['name' => 'ATLAS Official', 'url' => 'https://www.playatlas.com/'],
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/834910/'],
            ],
            'dark-and-light' => [
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/529180/'],
            ],
            'hell-let-loose' => [
                ['name' => 'Hell Let Loose Official', 'url' => 'https://www.hellletloose.com/'],
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/686810/'],
            ],
            'insurgency-sandstorm' => [
                ['name' => 'Sandstorm Official', 'url' => 'https://sandstorm.game/'],
                ['name' => 'Mods and server content', 'url' => 'https://mod.io/g/insurgencysandstorm'],
            ],
            'insurgency' => [
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/222880/'],
                ['name' => 'Dedicated server docs', 'url' => 'https://developer.valvesoftware.com/wiki/Insurgency_Dedicated_Server'],
            ],
            'mordhau' => [
                ['name' => 'MORDHAU Official', 'url' => 'https://mordhau.com/'],
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/629760/'],
            ],
            'squad-44' => [
                ['name' => 'Squad 44 Official', 'url' => 'https://squad44.com/'],
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/736220/'],
            ],
            'beyond-the-wire' => [
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/1058650/'],
            ],
            'rising-storm-2-vietnam' => [
                ['name' => 'Rising Storm 2 Official', 'url' => 'https://rs2vietnam.com/'],
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/418460/'],
            ],
            'battalion-legacy' => [
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/489940/'],
            ],
            '83' => [
                ['name' => "'83 Official", 'url' => 'https://83thegame.com/'],
                ['name' => 'Steam page', 'url' => 'https://store.steampowered.com/app/1059220/'],
            ],
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
