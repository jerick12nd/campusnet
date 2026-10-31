<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PollDeviceJob;
use App\Models\MonitoredDevice;
use App\Monitoring\Exceptions\DriverUnavailableException;
use App\Monitoring\MonitoringManager;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

/**
 *   php artisan campusnet:poll                 devices that are due (what the scheduler runs)
 *   php artisan campusnet:poll --force --sync  every enabled device, right now, with results printed
 *   php artisan campusnet:poll --device=3      one device
 */
class PollDevicesCommand extends Command
{
    protected $signature = 'campusnet:poll
        {--device= : Poll only this device id, even if it is not due yet}
        {--force : Poll every enabled device now, not only the ones that are due}
        {--sync : Poll in this process instead of sending jobs to the queue}';

    protected $description = 'Poll the monitored devices that are due';

    /** The scheduler can start a few seconds late; without this slack a 60 s interval often becomes 120 s. */
    private const DUE_SLACK_SECONDS = 5;

    public function handle(MonitoringManager $monitoring): int
    {
        try {
            $monitoring->pollingDriver();
        } catch (DriverUnavailableException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $devices = MonitoredDevice::query()
            ->enabled()
            // Demo devices are only ever polled by the mock driver, real devices only by a real one.
            ->where('is_demo', $monitoring->isDemo())
            ->when($this->option('device'), fn ($query, $id) => $query->whereKey((int) $id))
            ->get();

        $everything = $this->option('force') || $this->option('device') !== null;
        $now = now();
        $due = $devices->filter(fn (MonitoredDevice $device): bool => $everything || $this->isDue($device, $now));
        $sync = (bool) $this->option('sync');

        if ($devices->isEmpty()) {
            $this->warn('No enabled '.($monitoring->isDemo() ? 'demo' : 'real').' devices match the active driver ('.$monitoring->driverName().').');

            return self::SUCCESS;
        }

        $this->info("Polling {$due->count()} of {$devices->count()} enabled devices ".($sync ? 'now.' : 'via the queue.'));

        foreach ($due as $device) {
            if ($sync) {
                PollDeviceJob::dispatchSync($device->id);

                $device->refresh();
                $this->line(sprintf(
                    '  %s: %s%s',
                    $device->name,
                    $device->last_status->label(),
                    $device->last_error ? " ({$device->last_error})" : '',
                ));
            } else {
                PollDeviceJob::dispatch($device->id);
            }
        }

        return self::SUCCESS;
    }

    private function isDue(MonitoredDevice $device, CarbonInterface $now): bool
    {
        if ($device->last_polled_at === null) {
            return true;
        }

        $interval = max(10, $device->polling_interval_seconds);

        return $now->getTimestamp() - $device->last_polled_at->getTimestamp() >= $interval - self::DUE_SLACK_SECONDS;
    }
}