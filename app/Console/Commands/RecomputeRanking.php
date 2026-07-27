<?php

namespace App\Console\Commands;

use App\Services\Catalog\ServerRanking;
use Illuminate\Console\Command;

class RecomputeRanking extends Command
{
    protected $signature = 'ranking:recompute';

    protected $description = 'Recompute server ranking points and vote totals';

    public function handle(ServerRanking $ranking): int
    {
        $updated = $ranking->recompute();

        $this->info("Ranking recomputed: {$updated} server(s) changed.");

        return self::SUCCESS;
    }
}
