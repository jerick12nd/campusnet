<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Enums\DeviceStatus;
use App\Enums\InterfaceRole;
use App\Enums\SampleStatus;
use App\Models\MonitoredDevice;
use App\Models\NetworkInterface;
use App\Monitoring\Contracts\PollingDriver;
use App\Monitoring\Exceptions\PollFailedException;
use App\Services\SettingsService;
use App\Support\Bandwidth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Polls one device with the given driver and records the result. It never invents
 * data: a failed poll produces a failed sample, never a made-up measurement.
 */
class PollingService
{
    /** A longer gap makes a rate meaningless, so the reading becomes a new baseline instead. */
    private const MAX_GAP_SECONDS = 900;

    /** Margin over the link speed, for timing jitter, before a rate is rejected. */
    private const SPEED_MARGIN = 1.25;

    public function __construct(private readonly SettingsService $settings) {}

    public function pollDevice(PollingDriver $driver, MonitoredDevice $device): PollOutcome
    {
        $polledAt = now()->toImmutable();
        $discovered = null;

        try {
            // A device with no interface rows yet gets them from the driver on its first poll.
            if (! $device->interfaces()->exists()) {
                $discovered = $driver->discoverInterfaces($device);
            }

            $readings = collect($driver->readCounters($device))->keyBy('ifIndex');
        } catch (PollFailedException $e) {
            return $this->recordFailure($driver, $device, $polledAt, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->recordFailure($driver, $device, $polledAt, 'The poll failed unexpectedly. Check the application log.');
        }

        if ($discovered !== null) {
            $this->syncInterfaces($device, $discovered);
        }

        return $this->recordSuccess($driver, $device, $polledAt, $readings);
    }

    /**
     * @param  Collection<int, InterfaceCounters>  $readings  keyed by ifIndex
     */
    private function recordSuccess(PollingDriver $driver, MonitoredDevice $device, CarbonImmutable $polledAt, Collection $readings): PollOutcome
    {
        $capacity = (float) $this->settings->get('bandwidth_capacity_mbps');
        $stamp = $polledAt->utc()->format('Y-m-d H:i:s');
        $rows = [];

        DB::transaction(function () use ($driver, $device, $polledAt, $readings, $capacity, $stamp, &$rows): void {
            foreach ($device->interfaces()->monitored()->get() as $interface) {
                $reading = $readings->get($interface->if_index);

                if ($reading === null) {
                    continue; // the device did not report this interface this time
                }

                $elapsed = $this->elapsedSeconds($interface, $polledAt);
                $speed = $reading->speedMbps ?? $interface->speed_mbps;

                $rate = CounterRate::compute(
                    $interface->last_in_octets,
                    $interface->last_out_octets,
                    $reading->inOctets,
                    $reading->outOctets,
                    $elapsed,
                    $reading->counterBits,
                    $speed !== null && $speed > 0 ? $speed * self::SPEED_MARGIN : null,
                );

                $rows[] = [
                    'monitored_device_id' => $device->id,
                    'network_interface_id' => $interface->id,
                    'sampled_at' => $stamp,
                    'in_octets' => $reading->inOctets,
                    'out_octets' => $reading->outOctets,
                    'in_mbps' => $rate->inMbps,
                    'out_mbps' => $rate->outMbps,
                    // Utilization is measured against the internet capacity, so only WAN rows carry it.
                    'utilization_percent' => $rate->status->hasValidRate() && $interface->role === InterfaceRole::Wan
                        ? Bandwidth::utilizationPercent($rate->inMbps ?? 0.0, $rate->outMbps ?? 0.0, $capacity)
                        : null,
                    'elapsed_seconds' => $elapsed,
                    'source' => $driver->source()->value,
                    'status' => $rate->status->value,
                    'is_successful' => true,
                    'error_message' => match ($rate->status) {
                        SampleStatus::CounterReset => 'Counter went backwards (device restart or counter reset).',
                        SampleStatus::Invalid => 'Rate was higher than the link can carry and was rejected.',
                        default => null,
                    },
                    'created_at' => $stamp,
                ];

                // The new counters become the next baseline whatever the status was.
                $interface->forceFill([
                    'last_in_octets' => $reading->inOctets,
                    'last_out_octets' => $reading->outOctets,
                    'last_polled_at' => $polledAt,
                    'oper_status' => $reading->operStatus ?? $interface->oper_status,
                    'speed_mbps' => $reading->speedMbps ?? $interface->speed_mbps,
                ])->save();
            }

            if ($rows !== []) {
                DB::table('traffic_samples')->insert($rows);
            }

            $device->forceFill([
                'last_polled_at' => $polledAt,
                'last_successful_poll_at' => $polledAt,
                'last_status' => DeviceStatus::Online,
                'last_error' => null,
            ])->save();
        });

        return new PollOutcome(true, count($rows));
    }

    /**
     * A failed poll is recorded as failed samples (one per monitored interface) and the
     * device becomes unreachable. Interface counters are left alone.
     */
    private function recordFailure(PollingDriver $driver, MonitoredDevice $device, CarbonImmutable $polledAt, string $message): PollOutcome
    {
        $message = Str::limit($message, 255, '');
        $stamp = $polledAt->utc()->format('Y-m-d H:i:s');

        DB::transaction(function () use ($driver, $device, $polledAt, $message, $stamp): void {
            $rows = $device->interfaces()->monitored()->get()
                ->map(fn (NetworkInterface $interface): array => [
                    'monitored_device_id' => $device->id,
                    'network_interface_id' => $interface->id,
                    'sampled_at' => $stamp,
                    'in_octets' => null,
                    'out_octets' => null,
                    'in_mbps' => null,
                    'out_mbps' => null,
                    'utilization_percent' => null,
                    'elapsed_seconds' => null,
                    'source' => $driver->source()->value,
                    'status' => SampleStatus::Failed->value,
                    'is_successful' => false,
                    'error_message' => $message,
                    'created_at' => $stamp,
                ])
                ->all();

            if ($rows !== []) {
                DB::table('traffic_samples')->insert($rows);
            }

            $device->forceFill([
                'last_polled_at' => $polledAt,
                'last_status' => DeviceStatus::Unreachable,
                'last_error' => $message,
            ])->save();
        });

        return new PollOutcome(false, 0, $message);
    }

    /** Seconds since the last successful counter read, or null when no rate can be trusted. */
    private function elapsedSeconds(NetworkInterface $interface, CarbonImmutable $polledAt): ?int
    {
        if ($interface->last_polled_at === null) {
            return null;
        }

        $elapsed = $polledAt->getTimestamp() - $interface->last_polled_at->getTimestamp();

        return $elapsed > self::MAX_GAP_SECONDS ? null : $elapsed;
    }

    /**
     * @param  list<DiscoveredInterface>  $discovered
     */
    private function syncInterfaces(MonitoredDevice $device, array $discovered): void
    {
        foreach ($discovered as $found) {
            $interface = NetworkInterface::firstOrNew([
                'monitored_device_id' => $device->id,
                'if_index' => $found->ifIndex,
            ]);

            $isNew = ! $interface->exists;

            $interface->fill([
                'name' => Str::limit($found->name, 120, ''),
                'description' => $found->description === null ? null : Str::limit($found->description, 255, ''),
                'oper_status' => $found->operStatus,
                'speed_mbps' => $found->speedMbps,
            ]);

            if ($isNew) {
                // Ports that are down are not monitored by default; an administrator can switch them on.
                $interface->is_monitored = $found->operStatus === 'up';
            }

            $interface->save();
        }
    }
}