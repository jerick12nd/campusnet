<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\DeviceStatus;
use App\Enums\SampleStatus;
use App\Models\MonitoredDevice;
use App\Models\NetworkAlert;
use App\Models\NetworkInterface;
use App\Monitoring\MonitoringManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns stored measurements and device states into alerts. It reads what the polling
 * pipeline already stored and never contacts a device itself.
 *
 * Rules:
 *  - Device unreachable: the last poll failed AND there has been no successful poll for
 *    longer than a short grace period, so one lost packet does not raise an alert.
 *  - Utilization: the average of the valid samples of a WAN interface over the last few
 *    minutes is compared with the warning and critical thresholds. A small margin below
 *    the warning threshold keeps an open alert open, so a value hovering around the
 *    threshold does not open and close alerts all day.
 *  - No recent measurements: monitored devices exist, but nothing valid arrived within the
 *    stale-data window (usually the scheduler is not running).
 *
 * An alert is identified by its dedup key. While its condition lasts there is exactly one
 * open alert; when the condition ends the alert resolves itself. Only demo devices are
 * evaluated in demo mode and only real devices when a real driver is active.
 */
final class AlertEvaluator
{
    /** How many minutes of samples are averaged for the utilization rule. */
    public const UTILIZATION_WINDOW_MINUTES = 5;

    /** Percentage points below the warning threshold in which an open alert is kept open. */
    public const CLEAR_MARGIN_POINTS = 5.0;

    /** A device must have been silent at least this long (or twice its polling interval) to be unreachable. */
    private const MIN_UNREACHABLE_GRACE_SECONDS = 120;

    /** @var array{opened: int, updated: int, resolved: int} */
    private array $stats = ['opened' => 0, 'updated' => 0, 'resolved' => 0];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly MonitoringManager $monitoring,
    ) {}

    /**
     * @return array{opened: int, updated: int, resolved: int}
     */
    public function evaluate(?CarbonImmutable $now = null): array
    {
        $this->stats = ['opened' => 0, 'updated' => 0, 'resolved' => 0];
        $now ??= CarbonImmutable::now();

        if (! $this->monitoring->isKnownDriver()) {
            return $this->stats;
        }

        $settings = $this->settings->all();
        $source = $this->monitoring->driverName();

        /** @var Collection<int, MonitoredDevice> $devices */
        $devices = MonitoredDevice::query()
            ->enabled()
            ->where('is_demo', $this->monitoring->isDemo())
            ->get();

        $this->evaluateDevices($devices, $now);
        $this->evaluateUtilization($devices, $source, $settings, $now);
        $this->evaluateCollector($devices, $source, $settings, $now);
        $this->resolveOrphans($now);

        return $this->stats;
    }

    /**
     * @param  Collection<int, MonitoredDevice>  $devices
     */
    private function evaluateDevices(Collection $devices, CarbonImmutable $now): void
    {
        foreach ($devices as $device) {
            $key = "device:{$device->id}:unreachable";

            if ($this->isUnreachable($device, $now)) {
                $description = 'The device is not answering polls.';

                if ($device->last_error) {
                    $description .= ' Last error: '.Str::limit((string) $device->last_error, 200);
                }

                $this->raise(
                    $key,
                    AlertType::DeviceUnreachable,
                    AlertSeverity::Critical,
                    "Device unreachable: {$device->name}",
                    $description,
                    $device->id,
                    null,
                    [
                        'management_ip' => $device->management_ip,
                        'last_successful_poll_at' => $device->last_successful_poll_at?->toIso8601String(),
                    ],
                    $now,
                );

                continue;
            }

            if ($device->last_status === DeviceStatus::Online) {
                $closed = $this->resolve($key, 'Resolved automatically: the device answers polls again.', $now);

                if ($closed !== null) {
                    $this->recordRecovery($device, $closed, $now);
                }
            }
        }
    }

    private function isUnreachable(MonitoredDevice $device, CarbonImmutable $now): bool
    {
        if ($device->last_status !== DeviceStatus::Unreachable) {
            return false;
        }

        $lastSuccess = $device->last_successful_poll_at;

        if ($lastSuccess === null) {
            return true; // it has never answered, which usually means a wrong address or credentials
        }

        $grace = max(self::MIN_UNREACHABLE_GRACE_SECONDS, 2 * (int) $device->polling_interval_seconds);

        return $lastSuccess->getTimestamp() <= $now->getTimestamp() - $grace;
    }

    /**
     * An informational event for the log. It is created already resolved, so it never
     * counts as an open alert.
     */
    private function recordRecovery(MonitoredDevice $device, NetworkAlert $closed, CarbonImmutable $now): void
    {
        $event = new NetworkAlert([
            'type' => AlertType::DeviceRecovered,
            'severity' => AlertSeverity::Info,
            'status' => AlertStatus::Resolved,
            'title' => "Device recovered: {$device->name}",
            'description' => 'The device answers polls again.',
            'monitored_device_id' => $device->id,
            'dedup_key' => "device:{$device->id}:recovered:{$now->getTimestamp()}",
            'metadata' => ['downtime_seconds' => max(0, $now->getTimestamp() - $closed->triggered_at->getTimestamp())],
            'triggered_at' => $now,
            'last_seen_at' => $now,
        ]);

        $event->forceFill(['resolved_at' => $now, 'resolution_note' => 'Informational event.'])->save();
    }

    /**
     * @param  Collection<int, MonitoredDevice>  $devices
     * @param  array<string, mixed>  $settings
     */
    private function evaluateUtilization(Collection $devices, string $source, array $settings, CarbonImmutable $now): void
    {
        if ($devices->isEmpty()) {
            return;
        }

        $warning = (float) $settings['warning_threshold_percent'];
        $critical = (float) $settings['critical_threshold_percent'];
        $since = $this->stamp($now->subMinutes(self::UTILIZATION_WINDOW_MINUTES));

        $interfaces = NetworkInterface::query()
            ->wan()
            ->monitored()
            ->whereIn('monitored_device_id', $devices->pluck('id')->all())
            ->with('device:id,name')
            ->get();

        foreach ($interfaces as $interface) {
            $average = DB::table('traffic_samples')
                ->where('network_interface_id', $interface->id)
                ->where('source', $source)
                ->where('status', SampleStatus::Ok->value)
                ->whereNotNull('utilization_percent')
                ->where('sampled_at', '>=', $since)
                ->avg('utilization_percent');

            if ($average === null) {
                continue; // nothing recent to judge by: this rule neither opens nor resolves anything
            }

            $average = (float) $average;
            $key = "interface:{$interface->id}:utilization";
            $label = "{$interface->name} on ".($interface->device?->name ?? 'an unknown device');
            $text = sprintf(
                'Average utilization is %.1f%% over the last %d minutes (warning at %s%%, critical at %s%%).',
                $average,
                self::UTILIZATION_WINDOW_MINUTES,
                $warning,
                $critical,
            );
            $metadata = [
                'average_percent' => round($average, 1),
                'window_minutes' => self::UTILIZATION_WINDOW_MINUTES,
                'warning_percent' => $warning,
                'critical_percent' => $critical,
            ];

            if ($average >= $critical) {
                $this->raise($key, AlertType::UtilizationCritical, AlertSeverity::Critical, "Critical utilization: {$label}", $text, $interface->monitored_device_id, $interface->id, $metadata, $now);
            } elseif ($average >= $warning) {
                $this->raise($key, AlertType::UtilizationWarning, AlertSeverity::Warning, "High utilization: {$label}", $text, $interface->monitored_device_id, $interface->id, $metadata, $now);
            } elseif ($average >= $warning - self::CLEAR_MARGIN_POINTS) {
                $this->touch($key, $now); // close to the threshold: keep what is open, open nothing new
            } else {
                $this->resolve($key, sprintf('Resolved automatically: utilization fell to %.1f%%.', $average), $now);
            }
        }
    }

    /**
     * @param  Collection<int, MonitoredDevice>  $devices
     * @param  array<string, mixed>  $settings
     */
    private function evaluateCollector(Collection $devices, string $source, array $settings, CarbonImmutable $now): void
    {
        $key = 'collector:no-data';

        if ($devices->isEmpty()) {
            $this->resolve($key, 'Resolved automatically: no devices are being monitored.', $now);

            return;
        }

        $minutes = max(1, (int) $settings['stale_data_minutes']);

        $latest = DB::table('traffic_samples')
            ->whereIn('monitored_device_id', $devices->pluck('id')->all())
            ->where('source', $source)
            ->where('is_successful', true)
            ->max('sampled_at');

        $latestAt = $latest === null ? null : CarbonImmutable::parse((string) $latest, 'UTC');

        // A device that was just added has had no chance to be measured yet, so the clock
        // starts when the oldest monitored device was created.
        $reference = $latestAt ?? CarbonImmutable::instance($devices->min('created_at') ?? $now);

        if ($reference->lt($now->subMinutes($minutes))) {
            $this->raise(
                $key,
                AlertType::NoRecentData,
                AlertSeverity::Warning,
                'No recent measurements',
                ($latestAt === null ? 'No valid measurement has been collected yet' : "The newest valid measurement is older than {$minutes} minutes")
                    .'. The collector (the scheduler, php artisan schedule:work) may not be running, so the dashboard values are not current.',
                null,
                null,
                ['latest_measurement_at' => $latestAt?->toIso8601String()],
                $now,
            );

            return;
        }

        $this->resolve($key, 'Resolved automatically: new measurements are arriving.', $now);
    }

    /**
     * Alerts of a device that was switched off or archived cannot clear themselves any
     * more, so they are closed rather than left open for ever.
     */
    private function resolveOrphans(CarbonImmutable $now): void
    {
        NetworkAlert::query()
            ->open()
            ->whereNotNull('monitored_device_id')
            ->where(function ($query): void {
                $query->whereDoesntHave('device')
                    ->orWhereHas('device', fn ($device) => $device->where('is_enabled', false));
            })
            ->get()
            ->each(fn (NetworkAlert $alert) => $this->close($alert, 'Resolved automatically: monitoring of this device was turned off or the device was archived.', $now));
    }

    /**
     * Opens the alert for a dedup key, or refreshes the one that is already open.
     * A higher severity re-activates an alert that someone had acknowledged.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function raise(
        string $key,
        AlertType $type,
        AlertSeverity $severity,
        string $title,
        ?string $description,
        ?int $deviceId,
        ?int $interfaceId,
        array $metadata,
        CarbonImmutable $now,
    ): NetworkAlert {
        $alert = NetworkAlert::query()->where('dedup_key', $key)->open()->latest('id')->first();

        if ($alert === null) {
            $alert = new NetworkAlert([
                'type' => $type,
                'severity' => $severity,
                'status' => AlertStatus::Active,
                'title' => Str::limit($title, 160, ''),
                'description' => $description,
                'monitored_device_id' => $deviceId,
                'network_interface_id' => $interfaceId,
                'dedup_key' => $key,
                'metadata' => $metadata,
                'triggered_at' => $now,
                'last_seen_at' => $now,
            ]);
            $alert->save();
            $this->stats['opened']++;

            return $alert;
        }

        $escalated = $severity->rank() > $alert->severity->rank();

        $alert->fill([
            'type' => $type,
            'severity' => $severity,
            'title' => Str::limit($title, 160, ''),
            'description' => $description,
            'metadata' => $metadata,
            'last_seen_at' => $now,
        ]);

        $changed = $alert->isDirty(['type', 'severity']);

        if ($escalated) {
            $alert->status = AlertStatus::Active;
            $alert->forceFill(['acknowledged_by' => null, 'acknowledged_at' => null]);
        }

        $alert->save();

        if ($changed) {
            $this->stats['updated']++;
        }

        return $alert;
    }

    private function touch(string $key, CarbonImmutable $now): void
    {
        NetworkAlert::query()->where('dedup_key', $key)->open()->update(['last_seen_at' => $now]);
    }

    private function resolve(string $key, string $note, CarbonImmutable $now): ?NetworkAlert
    {
        $alert = NetworkAlert::query()->where('dedup_key', $key)->open()->latest('id')->first();

        return $alert === null ? null : $this->close($alert, $note, $now);
    }

    private function close(NetworkAlert $alert, string $note, CarbonImmutable $now): NetworkAlert
    {
        $alert->forceFill([
            'status' => AlertStatus::Resolved,
            'resolved_at' => $now,
            'resolved_by' => null,
            'resolution_note' => Str::limit($note, 255, ''),
            'last_seen_at' => $now,
        ])->save();

        $this->stats['resolved']++;

        return $alert;
    }

    private function stamp(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d H:i:s');
    }
}