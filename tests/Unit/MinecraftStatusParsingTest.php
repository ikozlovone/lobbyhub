<?php

namespace Tests\Unit;

use App\Services\Monitoring\Drivers\MinecraftQueryDriver;
use App\Services\Monitoring\Exceptions\QueryFailed;
use Tests\TestCase;

class MinecraftStatusParsingTest extends TestCase
{
    private MinecraftQueryDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new MinecraftQueryDriver;
    }

    public function test_it_reads_a_typical_status_response(): void
    {
        $result = $this->driver->parseStatus(json_encode([
            'version' => ['name' => '1.21.4', 'protocol' => 769],
            'players' => ['online' => 42, 'max' => 200],
            'description' => 'Welcome to the server',
        ]), latencyMs: 37, ip: '1.2.3.4');

        $this->assertSame(42, $result->playersOnline);
        $this->assertSame(200, $result->playersMax);
        $this->assertSame('1.21.4', $result->version);
        $this->assertSame('Welcome to the server', $result->motd);
        $this->assertSame(37, $result->latencyMs);
        $this->assertSame('1.2.3.4', $result->ipAddress);
        $this->assertNull($result->map); // Minecraft status carries no map
    }

    public function test_it_flattens_a_chat_component_motd_and_strips_colour_codes(): void
    {
        $result = $this->driver->parseStatus(json_encode([
            'description' => [
                'text' => '§aHypixel ',
                'extra' => [
                    ['text' => 'Network'],
                    ['text' => ' §e[1.8-1.21]'],
                ],
            ],
        ]));

        $this->assertSame('Hypixel Network [1.8-1.21]', $result->motd);
    }

    public function test_it_clamps_hidden_player_counts(): void
    {
        // Servers that hide their counts report -1.
        $result = $this->driver->parseStatus(json_encode([
            'players' => ['online' => -1, 'max' => -1],
        ]));

        $this->assertSame(0, $result->playersOnline);
        $this->assertSame(0, $result->playersMax);
    }

    public function test_it_tolerates_a_response_missing_optional_fields(): void
    {
        $result = $this->driver->parseStatus('{}');

        $this->assertSame(0, $result->playersOnline);
        $this->assertNull($result->version);
        $this->assertNull($result->motd);
    }

    public function test_it_caps_latency_at_the_column_width(): void
    {
        $result = $this->driver->parseStatus('{}', latencyMs: 90_000);

        $this->assertSame(65535, $result->latencyMs);
    }

    public function test_it_rejects_a_non_json_response(): void
    {
        $this->expectException(QueryFailed::class);

        $this->driver->parseStatus('<html>not minecraft</html>');
    }
}
