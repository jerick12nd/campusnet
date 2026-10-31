<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MonitoredDevice;
use App\Monitoring\MonitoringManager;
use App\Monitoring\PollingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PollDeviceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A failed poll is followed by the next scheduled one; a retry would distort the elapsed time. */
    public int $tries = 1;

    public int $timeout = 60;

    /** One poll per device at a time; the lock expires on its own if a worker dies. */
    public int $uniqueFor = 120;

    public function __construct(public readonly int $deviceId) {}

    public function uniqueId(): string
    {
        return (string) $this->deviceId;
    }

    public function handle(MonitoringManager $monitoring, PollingService $polling): void
    {
        $device = MonitoredDevice::query()->enabled()->find($this->deviceId);

        if ($device === null) {
            return; // disabled or archived since the job was queued
        }

        $polling->pollDevice($monitoring->pollingDriver(), $device);
    }
}