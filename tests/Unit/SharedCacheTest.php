<?php

namespace Tests\Unit;

use App\Services\Http\SharedCache;
use PHPUnit\Framework\TestCase;

/**
 * The layout half of dropping an nginx cache entry.
 *
 * Everything else about this class is a file operation; what can quietly go
 * wrong is where it looks. nginx takes the level directories from the *end* of
 * the hash, and an off-by-one or a reversed pair produces a path that never
 * exists — which fails exactly the way a cache miss looks, silently, forever.
 * So the expected path here is written out by hand rather than computed.
 */
class SharedCacheTest extends TestCase
{
    /** md5('GET/api/servers/rust-legacy') = ea3b603ec059e62fe62b28056844c97b */
    public function test_it_puts_an_entry_where_nginx_would_have(): void
    {
        $root = sys_get_temp_dir().'/lobbyhub-shared-'.bin2hex(random_bytes(6));
        $hash = 'ea3b603ec059e62fe62b28056844c97b';

        // levels=1:2 — last character, then the two before it.
        $file = "{$root}/b/97/{$hash}";
        mkdir(dirname($file), 0755, recursive: true);
        file_put_contents($file, 'stored answer');

        $cache = new SharedCache($root, '1:2');

        $this->assertTrue($cache->forget('/api/servers/rust-legacy'));
        $this->assertFileDoesNotExist($file);
        // A second drop is not a failure — it is the ordinary case of nobody
        // having read that URL since it was last written.
        $this->assertFalse($cache->forget('/api/servers/rust-legacy'));

        rmdir(dirname($file));
    }

    /**
     * The query string is part of what nginx keyed on, so it is part of what
     * has to be handed back — `?range=24h` is its own entry.
     */
    public function test_the_query_string_is_part_of_the_entry(): void
    {
        $root = sys_get_temp_dir().'/lobbyhub-shared-'.bin2hex(random_bytes(6));
        $cache = new SharedCache($root, '1:2');

        $hash = md5('GET/api/servers/rust-legacy/history?range=24h');
        $file = $root.'/'.substr($hash, 31, 1).'/'.substr($hash, 29, 2).'/'.$hash;
        mkdir(dirname($file), 0755, recursive: true);
        file_put_contents($file, 'stored answer');

        // The same path without the query is a different key and must miss.
        $this->assertFalse($cache->forget('/api/servers/rust-legacy/history'));
        $this->assertTrue($cache->forget('/api/servers/rust-legacy/history?range=24h'));

        rmdir(dirname($file));
    }

    /** No path configured is a machine with no shared cache in front of it. */
    public function test_an_unconfigured_cache_drops_nothing(): void
    {
        $cache = new SharedCache(null, '1:2');

        $this->assertFalse($cache->isConfigured());
        $this->assertFalse($cache->forget('/api/servers/rust-legacy'));
    }
}
