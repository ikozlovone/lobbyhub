<?php

namespace Tests\Unit;

use App\Services\Catalog\ServerAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ServerAddressTest extends TestCase
{
    #[DataProvider('accepted')]
    public function test_it_reads_the_forms_people_actually_paste(string $input, string $host, int $port): void
    {
        $address = ServerAddress::parse($input, 25565);

        $this->assertNotNull($address, "[{$input}] should parse");
        $this->assertSame($host, $address->host);
        $this->assertSame($port, $address->port);
    }

    public static function accepted(): array
    {
        return [
            'ip and port' => ['1.2.3.4:28015', '1.2.3.4', 28015],
            'domain and port' => ['play.example.com:30120', 'play.example.com', 30120],
            'bare domain takes the game default' => ['play.example.com', 'play.example.com', 25565],
            'padded' => ['  1.2.3.4:28015  ', '1.2.3.4', 28015],
            'uppercased domain' => ['PLAY.Example.COM:25565', 'play.example.com', 25565],
            'console line' => ['connect 1.2.3.4:28015', '1.2.3.4', 28015],
            'steam link' => ['steam://connect/1.2.3.4:28015', '1.2.3.4', 28015],
            'http endpoint' => ['http://play.example.com:30120/', 'play.example.com', 30120],
            'ipv6 in brackets' => ['[2001:db8::1]:28015', '2001:db8::1', 28015],
            'bare ipv6' => ['2001:db8::1', '2001:db8::1', 25565],
        ];
    }

    #[DataProvider('rejected')]
    public function test_it_refuses_what_is_not_an_address(string $input): void
    {
        $this->assertNull(ServerAddress::parse($input, 25565), "[{$input}] should not parse");
    }

    public static function rejected(): array
    {
        return [
            'empty' => [''],
            'prose' => ['my server'],
            // Single-label names are never something another player can join.
            'localhost' => ['localhost:25565'],
            'no tld' => ['gameserver:25565'],
            'port out of range' => ['1.2.3.4:99999'],
            'port zero' => ['1.2.3.4:0'],
            'port is not a number' => ['1.2.3.4:abc'],
            'truncated ip' => ['1.2.3:25565'],
        ];
    }
}
