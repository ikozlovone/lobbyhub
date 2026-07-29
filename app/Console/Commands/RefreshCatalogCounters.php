<?php

namespace App\Console\Commands;

use App\Services\Catalog\CatalogCounters;
use Illuminate\Console\Command;

class RefreshCatalogCounters extends Command
{
    protected $signature = 'counters:refresh';

    protected $description = 'Recompute the denormalized server and player counters used by catalog pages';

    /**
     * The schedule's half of the job. The other caller is the submission form,
     * which cannot wait five minutes to admit that the catalog grew — hence the
     * work living in a service rather than here.
     */
    public function handle(CatalogCounters $counters): int
    {
        foreach ($counters->refresh() as $table => $rows) {
            $this->line(sprintf('  %-14s %d row(s) with servers', $table, $rows));
        }

        $this->info('Catalog counters refreshed.');

        return self::SUCCESS;
    }
}
