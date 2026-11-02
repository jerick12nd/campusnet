<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Monitoring\MonitoringManager;
use App\Services\AlertEvaluator;
use Illuminate\Console\Command;

/**
 *   php artisan campusnet:evaluate-alerts
 *
 * Run every minute by the scheduler. It only reads stored measurements, so it is safe to
 * run by hand at any time, for example right after php artisan campusnet:poll --force --sync.
 */
class EvaluateAlertsCommand extends Command
{
    protected $signature = 'campusnet:evaluate-alerts';

    protected $description = 'Open, update and resolve alerts from the stored measurements';

    public function handle(AlertEvaluator $evaluator, MonitoringManager $monitoring): int
    {
        if (! $monitoring->isKnownDriver()) {
            $this->error('BANDWIDTH_MONITORING_DRIVER has an unknown value, so no alerts were evaluated.');

            return self::FAILURE;
        }

        $result = $evaluator->evaluate();

        $this->info(sprintf(
            'Alerts evaluated (%s): %d opened, %d changed severity, %d resolved.',
            $monitoring->driverName(),
            $result['opened'],
            $result['updated'],
            $result['resolved'],
        ));

        return self::SUCCESS;
    }
}