<?php

namespace App\Services\Monitoring\Contracts;

use App\Models\Server;
use App\Services\Monitoring\Exceptions\QueryFailed;

/**
 * A driver that can fetch the slow-moving extras a server publishes about
 * itself — map, description, images, tuning values.
 *
 * Separate from ServerQueryDriver because it costs an extra round trip and the
 * data barely changes: it is refreshed on its own, much slower cadence.
 */
interface ProvidesServerDetails
{
    /**
     * @return array<string, string> normalized key/value pairs, empty when the
     *                               server answers but publishes nothing
     *
     * @throws QueryFailed
     */
    public function details(Server $server): array;
}
