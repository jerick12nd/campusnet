<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActivityDataSource;
use App\Models\DeviceConnectionSession;
use App\Models\DomainActivityRecord;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class PruneOfficeNetworkDataCommand extends Command
{
    protected $signature = 'office-network:prune {--dry-run : Count what would be deleted without deleting it}';

    protected $description = 'Delete office network records older than the retention period of their data source.';

    public function handle(AuditLogger $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $totals = ['domain_records' => 0, 'connection_sessions' => 0];

        // Retention applies to every source, enabled or not: switching a source off must not keep its data forever.
        foreach (ActivityDataSource::query()->get() as $source) {
            $cutoff = now()->subDays(max(1, (int) $source->retention_days));

            $records = DomainActivityRecord::query()
                ->where('activity_data_source_id', $source->id)
                ->where('observed_at', '<', $cutoff);

            $totals['domain_records'] += $dryRun ? $records->count() : $this->deleteInChunks($records);

            if ($source->type === 'dhcp') {
                // Only closed sessions: a connection that is still open is never removed.
                $sessions = DeviceConnectionSession::query()
                    ->where('activity_data_source_id', $source->id)
                    ->whereNotNull('ended_at')
                    ->where('ended_at', '<', $cutoff);

                $totals['connection_sessions'] += $dryRun ? $sessions->count() : $this->deleteInChunks($sessions);
            }
        }

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info("{$verb} {$totals['domain_records']} domain record(s) and {$totals['connection_sessions']} connection session(s).");

        if (! $dryRun && array_sum($totals) > 0) {
            $audit->record(
                action: 'office_network.retention_pruned',
                description: 'Deleted office network records past their retention period.',
                metadata: $totals,
            );
        }

        return self::SUCCESS;
    }

    private function deleteInChunks(Builder $query): int
    {
        $total = 0;

        do {
            $deleted = (clone $query)->limit(5000)->delete();
            $total += $deleted;
        } while ($deleted === 5000);

        return $total;
    }
}