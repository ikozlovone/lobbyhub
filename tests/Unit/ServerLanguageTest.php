<?php

namespace Tests\Unit;

use App\Services\Catalog\ServerLanguage;
use PHPUnit\Framework\TestCase;

class ServerLanguageTest extends TestCase
{
    private ServerLanguage $language;

    protected function setUp(): void
    {
        parent::setUp();

        $this->language = new ServerLanguage;
    }

    public function test_a_name_in_cyrillic_is_a_russian_server(): void
    {
        $this->assertSame('Russian', $this->detect('РОССИЯ X2 / ДЛЯ НОВИЧКОВ')['name']);
    }

    public function test_the_script_wins_over_the_latin_around_it(): void
    {
        // Nearly every Russian Rust server mixes the two like this.
        $this->assertSame('Russian', $this->detect('MIRAGE RUST | ВАЙП 27.07 | x2')['name']);
    }

    public function test_it_reads_the_tag_an_owner_wrote(): void
    {
        $this->assertSame('Turkish', $this->detect('[TR] ANADOLU RUST 5X')['name']);
        $this->assertSame('German', $this->detect('(DE) Rust Deutschland Main')['name']);
        $this->assertSame('Portuguese', $this->detect('|BR| RUST BRASIL 10X')['name']);
    }

    public function test_a_region_tag_is_not_a_language(): void
    {
        // "[EU]" covers two dozen of them, and the server says nothing else.
        $this->assertNull($this->detect('[EU] Rusty Moose Mondays'));
        $this->assertNull($this->detect('[NA] Bloo Lagoon'));
    }

    public function test_a_plain_latin_name_stays_unanswered(): void
    {
        // Not English by default: the row is meant to be a fact, and half of
        // these are Polish or Turkish servers that never tagged themselves.
        $this->assertNull($this->detect('Rustafied.com - EU Friday'));
    }

    public function test_a_tag_in_the_middle_of_a_name_is_not_a_claim(): void
    {
        $this->assertNull($this->detect('Vanilla Rust [PVP] no limit'));
    }

    public function test_the_blurb_counts_when_the_name_gives_nothing_away(): void
    {
        $found = $this->language->detect('Amber Rust 2x', null, 'Заходи, вайп каждый понедельник');

        $this->assertSame('Russian', $found['name']);
    }

    public function test_a_server_that_says_nothing_gets_no_row(): void
    {
        $this->assertNull($this->language->detect(''));
        $this->assertNull($this->language->detect(null));
    }

    /** @return array{code: string, name: string}|null */
    private function detect(string $name): ?array
    {
        return $this->language->detect($name);
    }
}
