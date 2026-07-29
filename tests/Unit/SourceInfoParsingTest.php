<?php

namespace Tests\Unit;

use App\Services\Monitoring\Drivers\SourceQueryDriver;
use App\Services\Monitoring\Exceptions\QueryFailed;
use Tests\TestCase;

class SourceInfoParsingTest extends TestCase
{
    private SourceQueryDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new SourceQueryDriver;
    }

    public function test_it_reads_a_rust_style_info_response(): void
    {
        $result = $this->driver->parseInfo($this->infoDatagram(
            name: 'Rustopia EU Main',
            map: 'Procedural Map',
            appId: 252490,
            players: 187,
            maxPlayers: 250,
            version: '2385.238.1',
        ), latencyMs: 58, ip: '1.2.3.4');

        $this->assertSame(187, $result->playersOnline);
        $this->assertSame(250, $result->playersMax);
        $this->assertSame('Procedural Map', $result->map);
        $this->assertSame('Rustopia EU Main', $result->motd);
        $this->assertSame('2385.238.1', $result->version);
        $this->assertSame(58, $result->latencyMs);
        $this->assertSame('1.2.3.4', $result->ipAddress);
    }

    /**
     * The single-byte counts cannot express a 300-slot server, so Rust also
     * publishes cp/mp tags — those must win.
     */
    public function test_tag_counts_override_the_single_byte_fields(): void
    {
        $result = $this->driver->parseInfo($this->infoDatagram(
            players: 44,   // 300 wrapped into a byte
            maxPlayers: 44,
            keywords: 'mp300,cp289,qp12,v2385,stok,gmrust,oxide,biweekly',
        ));

        $this->assertSame(289, $result->playersOnline);
        $this->assertSame(300, $result->playersMax);
    }

    public function test_it_falls_back_to_byte_counts_without_tags(): void
    {
        $result = $this->driver->parseInfo($this->infoDatagram(players: 12, maxPlayers: 64));

        $this->assertSame(12, $result->playersOnline);
        $this->assertSame(64, $result->playersMax);
    }

    public function test_it_reads_the_game_port_the_server_reports(): void
    {
        // A real Rust server queried on 28015 reported 28010 as its game port.
        $extra = chr(0x80 | 0x20).pack('v', 28010).'gmrust'."\x00";

        $result = $this->driver->parseInfo($this->infoDatagram(extra: $extra));

        $this->assertSame(28010, $result->gamePort);
    }

    public function test_no_game_port_is_reported_without_the_flag(): void
    {
        $this->assertNull($this->driver->parseInfo($this->infoDatagram())->gamePort);
    }

    public function test_it_reads_the_wipe_time_and_join_queue_from_tags(): void
    {
        $wipedAt = now()->subDays(3)->startOfSecond();

        $result = $this->driver->parseInfo($this->infoDatagram(
            keywords: "mp205,cp202,qp12,born{$wipedAt->timestamp},gmrust",
        ));

        $this->assertTrue($wipedAt->equalTo($result->wipedAt));
        $this->assertSame(12, $result->playersQueued);
    }

    public function test_it_rejects_a_nonsense_wipe_timestamp(): void
    {
        $this->assertNull($this->driver->parseInfo($this->infoDatagram(keywords: 'born1'))->wipedAt);
        $this->assertNull($this->driver->parseInfo($this->infoDatagram(keywords: 'born99999999999'))->wipedAt);
    }

    public function test_games_without_rust_tags_leave_the_extra_fields_empty(): void
    {
        // ARK-style tags: nothing here matches cp/mp/qp/born.
        $result = $this->driver->parseInfo($this->infoDatagram(
            players: 12,
            maxPlayers: 70,
            keywords: 'OWNINGID:9016,SESSIONISPVE_l_0,ServerVersion_400',
        ));

        $this->assertSame(12, $result->playersOnline);
        $this->assertSame(70, $result->playersMax);
        $this->assertNull($result->wipedAt);
        $this->assertNull($result->playersQueued);
    }

    public function test_it_walks_past_every_extra_data_field_before_the_keywords(): void
    {
        // All four preceding EDF fields present: port, steam id, spectator pair, keywords.
        $extra = chr(0x80 | 0x10 | 0x40 | 0x20)
            .pack('v', 28015)
            .str_repeat("\x00", 8)
            .pack('v', 28016)."SourceTV\x00"
            ."mp200,cp150\x00";

        $result = $this->driver->parseInfo($this->infoDatagram(players: 1, maxPlayers: 2, extra: $extra));

        $this->assertSame(150, $result->playersOnline);
        $this->assertSame(200, $result->playersMax);
    }

    public function test_it_skips_the_extra_fields_the_ship_adds(): void
    {
        $result = $this->driver->parseInfo($this->infoDatagram(
            appId: 2400,
            players: 7,
            maxPlayers: 8,
            version: '1.0.0.5',
        ));

        // Without the 3-byte skip, the version string would be misread.
        $this->assertSame('1.0.0.5', $result->version);
        $this->assertSame(7, $result->playersOnline);
    }

    public function test_it_rejects_a_challenge_packet_as_an_info_response(): void
    {
        $this->expectException(QueryFailed::class);
        $this->expectExceptionMessage('unexpected response type [A]');

        $this->driver->parseInfo("\xFF\xFF\xFF\xFFA\x01\x02\x03\x04");
    }

    public function test_it_rejects_a_split_response(): void
    {
        $this->expectException(QueryFailed::class);
        $this->expectExceptionMessage('split A2S_INFO response');

        $this->driver->parseInfo("\xFF\xFF\xFF\xFE".str_repeat("\x00", 20));
    }

    public function test_it_rejects_a_truncated_response(): void
    {
        $this->expectException(QueryFailed::class);

        $this->driver->parseInfo("\xFF\xFF\xFF\xFFI\x11Rust");
    }

    public function test_it_reads_the_bot_count_and_anti_cheat_byte(): void
    {
        // Both sit between fields the parser already needed, so they were read
        // and dropped for as long as this driver has existed.
        $result = $this->driver->parseInfo($this->infoDatagram(bots: 4, vac: 1));

        $this->assertSame(4, $result->bots);
        $this->assertTrue($result->vacEnabled);
    }

    public function test_anti_cheat_off_is_reported_as_off_not_as_unknown(): void
    {
        $result = $this->driver->parseInfo($this->infoDatagram(bots: 0, vac: 0));

        $this->assertSame(0, $result->bots);
        $this->assertFalse($result->vacEnabled);
    }

    /**
     * Build an A2S_INFO reply the way a real server frames one.
     */
    private function infoDatagram(
        string $name = 'Test Server',
        string $map = 'Procedural Map',
        int $appId = 252490,
        int $players = 10,
        int $maxPlayers = 100,
        string $version = '1.0.0',
        ?string $keywords = null,
        ?string $extra = null,
        int $bots = 0,
        int $vac = 0,
    ): string {
        $body = "\xFF\xFF\xFF\xFF".'I'
            .chr(17)                    // protocol version
            .$name."\x00"
            .$map."\x00"
            ."rust\x00"                 // folder
            ."Rust\x00"                 // game
            .pack('v', $appId)
            .chr($players)
            .chr($maxPlayers)
            .chr($bots)
            .'d'                        // server type: dedicated
            .'l'                        // environment: linux
            .chr(0)                     // visibility: public
            .chr($vac);

        if ($appId === 2400) {
            $body .= chr(0).chr(0).chr(0); // mode, witnesses, duration
        }

        $body .= $version."\x00";

        if ($extra !== null) {
            return $body.$extra;
        }

        if ($keywords !== null) {
            $body .= chr(0x20).$keywords."\x00";
        }

        return $body;
    }
}
