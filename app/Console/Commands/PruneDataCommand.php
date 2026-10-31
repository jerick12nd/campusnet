<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\TrafficSample;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 *   php artisan campusnet:prune             delete data older than the configured retention
 *   php artisan campusnet:prune --dry-run   only count what would be deleted
 */
class PruneDataCommand extends Command
{
    protected $signature = 'campusnet:prune {--dry-run : Count what would be deleted without deleting it}';

    protected $description = 'Delete traffic samples and audit logs older than the configured retention';

    /** Deleting in small batches keeps the table lock short while the collector keeps writing. */
    private const CHUNK = 5000;

    public function handle(AuditLogger $audit): int
    {
        $sampleDays = max(1, (int) config('campusnet.retention.traffic_samples_days'));
        $auditDays = max(1, (int) config('campusnet.retention.audit_logs_days'));

        // Timestamps are stored in UTC, so the cutoff is computed in UTC as well.
        $now = CarbonImmutable::now('UTC');

        $samples = TrafficSample::query()->where('sampled_at', '<', $now->subDays($sampleDays));
        $logs = AuditLog::query()->where('created_at', '<', $now->subDays($auditDays));

        if ($this->option('dry-run')) {
            $this->info("Would delete {$samples->count()} traffic samples older than {$sampleDays} days and {$logs->count()} audit logs older than {$auditDays} days.");

            return self::SUCCESS;
        }

        $counts = [
            'traffic_samples' => $this->deleteInChunks($samples),
            'audit_logs' => $this->deleteInChunks($logs),
        ];

        $this->info("Deleted {$counts['traffic_samples']} traffic samples and {$counts['audit_logs']} audit logs.");

        // A quiet night leaves no audit noise; only real deletions are recorded.
        if (array_sum($counts) > 0) {
            $audit->record('data.pruned', null, $counts + [
                'traffic_samples_days' => $sampleDays,
                'audit_logs_days' => $auditDays,
            ], 'Old data removed by the retention policy.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function deleteInChunks(Builder $query): int
    {
        $total = 0;

        do {
            $ids = (clone $query)->orderBy('id')->limit(self::CHUNK)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += $query->getModel()->newQuery()->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK);

        return $total;
    }
}