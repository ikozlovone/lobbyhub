<?php

namespace App\Services\Http;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drops one answer out of the cache nginx keeps in front of PHP.
 *
 * The windows in `routes/api.php` are the right default — a minute in front of
 * a server's page costs a minute of staleness that nothing on the page can tell
 * you about, and saves the framework boot on every read of it. What they cannot
 * express is the one moment somebody *knows* the answer changed: a visitor
 * presses Refresh, the panel updates from the POST, and a reload two seconds
 * later is served the copy nginx stored before the query went out. The button
 * says "we queried the server just now" and the page then disagrees with it.
 *
 * So this is the same shape as the Go sweeper's Redis `DEL` after a sweep: the
 * layer that wrote the new value tells the layer holding the old one to let go.
 *
 * How it finds the file: nginx stores each entry under the MD5 of its
 * `fastcgi_cache_key`, in the directory tree `levels=` describes — for
 * `levels=1:2`, the last character of the hash, then the two before it. The key
 * itself is `"$request_method$request_uri"` (see the API's server block), which
 * is why this needs nothing from the request beyond the URI it would have been
 * read with.
 *
 * Everything here is fail-open and silent by default. An unset path is a
 * machine with no nginx in front of it — local development, the test suite —
 * and a missing file is the normal case, not an error: it means nobody had
 * asked for that URL since the last write.
 */
class SharedCache
{
    public function __construct(
        private readonly ?string $path,
        private readonly string $levels,
    ) {}

    /** Whether there is a cache directory to drop anything from. */
    public function isConfigured(): bool
    {
        return $this->path !== null && $this->path !== '';
    }

    /**
     * Forget the stored answer for one URI — path and query string exactly as
     * nginx saw them in `$request_uri`, so `/api/servers/x?range=24h` is a
     * different entry from `/api/servers/x`.
     *
     * @return bool whether a stored copy was actually removed
     */
    public function forget(string $uri, string $method = 'GET'): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $file = $this->fileFor($method.$uri);

        try {
            // The race this loses is harmless: nginx writing the entry between
            // the check and the unlink leaves a copy that is at most as old as
            // the read that just went through PHP.
            return is_file($file) && @unlink($file);
        } catch (Throwable $e) {
            // Wrong owner on the cache directory is the one cause worth
            // knowing about, and it is a deploy fact rather than a per-request
            // one — so it is logged, and the refresh it happened during still
            // answers.
            Log::warning('Shared cache drop failed', ['uri' => $uri, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Where nginx would have put that key.
     *
     * `levels=1:2` means two directories deep, taking 1 then 2 characters from
     * the *end* of the hash: `…d3f0` becomes `0/3f/<hash>`.
     */
    private function fileFor(string $key): string
    {
        $hash = md5($key);
        $offset = strlen($hash);
        $dirs = [];

        foreach (explode(':', $this->levels) as $level) {
            $length = (int) trim($level);

            if ($length < 1) {
                continue;
            }

            $offset -= $length;
            $dirs[] = substr($hash, $offset, $length);
        }

        return rtrim($this->path, '/').'/'.implode('/', [...$dirs, $hash]);
    }
}
