<?php

namespace App\Console\Commands;

use App\Models\ScanRun;
use Illuminate\Console\Command;

class PruneNetworkScans extends Command
{
    protected $signature = 'network:prune {--days=30}';
    protected $description = 'I-delete ang scan data nga mas daan sa gitakda nga adlaw';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));

        // Ang scan_hosts mapapas sa cascadeOnDelete
        $deleted = ScanRun::where('scanned_at', '<', $cutoff)->delete();

        $this->info("{$deleted} ka daan nga scan ang na-delete.");

        return self::SUCCESS;
    }
}