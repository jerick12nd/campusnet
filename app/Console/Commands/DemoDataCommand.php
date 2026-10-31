<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DeviceStatus;
use App\Enums\DeviceType;
use App\Enums\InterfaceRole;
use App\Models\MonitoredDevice;
use App\Models\NetworkAlert;
use App\Models\NetworkInterface;
use App\Models\TrafficSample;
use App\Monitoring\MonitoringManager;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Support\Bandwidth;
use App\Support\DemoTraffic;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates clearly simulated devices, interfaces and traffic so the dashboard can be
 * demonstrated before real equipment is connected.
 *
 *   php artisan campusnet:demo-data            create (mock driver only)
 *   php artisan campusnet:demo-data --purge    remove all demo data (any driver)
 */
class DemoDataCommand extends Command
{
    protected $signature = 'campusnet:demo-data
        {--days=7 : Days of simulated history to create (1-30)}
        {--purge : Remove all demo data instead of creating it}
        {--force : Do not ask for confirmation when purging}';

    protected $description = 'Create or remove simulated demo devices and traffic (demo mode only)';

    /** Documentation addresses (RFC 5737): they can never be real equipment. */
    private const DEVICES = [
        [
            'name' => 'DEMO Core Router', 'ip' => '192.0.2.1', 'type' => DeviceType::Router, 'vendor' => 'MikroTik',
            'model' => 'CCR2004', 'location' => 'ICT Office', 'online' => true,
            'interfaces' => [
                ['index' => 1, 'name' => 'ether1-WAN', 'role' => InterfaceRole::Wan, 'weight' => 1.0],
                ['index' => 2, 'name' => 'bridge-LAN', 'role' => InterfaceRole::Lan, 'weight' => 0.9],
            ],
        ],
        [
            'name' => 'DEMO Switch - Main Building', 'ip' => '192.0.2.2', 'type' => DeviceType::NetworkSwitch, 'vendor' => 'Cisco',
            'model' => 'Catalyst 2960', 'location' => 'Main Building', 'online' => true,
            'interfaces' => [
                ['index' => 1, 'name' => 'Gi0/1 uplink', 'role' => InterfaceRole::Lan, 'weight' => 0.6],
                ['index' => 2, 'name' => 'Gi0/2 computer lab', 'role' => InterfaceRole::Lan, 'weight' => 0.3],
            ],
        ],
        [
            'name' => 'DEMO Switch - Library', 'ip' => '192.0.2.3', 'type' => DeviceType::NetworkSwitch, 'vendor' => 'Cisco',
            'model' => 'Catalyst 2960', 'location' => 'Library', 'online' => true,
            'interfaces' => [
                ['index' => 1, 'name' => 'Gi0/1 uplink', 'role' => InterfaceRole::Lan, 'weight' => 0.35],
            ],
        ],
        [
            'name' => 'DEMO AP - Library', 'ip' => '192.0.2.4', 'type' => DeviceType::AccessPoint, 'vendor' => 'Ubiquiti',
            'model' => 'UniFi U6', 'location' => 'Library', 'online' => false,
            'interfaces' => [
                ['index' => 1, 'name' => 'Wi-Fi 5GHz', 'role' => InterfaceRole::Lan, 'weight' => 0.2],
            ],
        ],
        [
            'name' => 'DEMO AP - Science Building', 'ip' => '192.0.2.5', 'type' => DeviceType::AccessPoint, 'vendor' => 'Ubiquiti',
            'model' => 'UniFi U6', 'location' => 'Science Building', 'online' => true,
            'interfaces' => [
                ['index' => 1, 'name' => 'Wi-Fi 5GHz', 'role' => InterfaceRole::Lan, 'weight' => 0.25],
            ],
        ],
    ];

    public function handle(MonitoringManager $monitoring, SettingsService $settings, AuditLogger $audit): int
    {
        if ($this->option('purge')) {
            return $this->purge($audit);
        }

        if (! $monitoring->isDemo()) {
            $this->error('Demo data can only be created while BANDWIDTH_MONITORING_DRIVER=mock. It is simulated and is never created for a real monitoring setup.');

            return self::FAILURE;
        }

        if (MonitoredDevice::withTrashed()->where('is_demo', true)->exists()) {
            $this->error('Demo data already exists. Run "php artisan campusnet:demo-data --purge" first to remove it.');

            return self::FAILURE;
        }

        $days = (int) $this->option('days');

        if ($days < 1 || $days > 30) {
            $this->error('--days must be between 1 and 30.');

            return self::FAILURE;
        }

        $capacity = (float) $settings->get('bandwidth_capacity_mbps');
        $timezone = $this->timezone((string) $settings->get('timezone'));
        $now = CarbonImmutable::now()->startOfMinute();
        $counts = ['devices' => 0, 'interfaces' => 0, 'samples' => 0];

        mt_srand(2026); // repeatable demo data

        DB::transaction(function () use ($days, $capacity, $timezone, $now, &$counts): void {
            foreach (self::DEVICES as $definition) {
                $online = $definition['online'];
                $lastSeen = $online ? $now : $now->subHours(3);

                $device = new MonitoredDevice([
                    'name' => $definition['name'],
                    'management_ip' => $definition['ip'],
                    'vendor' => $definition['vendor'],
                    'model' => $definition['model'],
                    'device_type' => $definition['type'],
                    'location' => $definition['location'],
                    'description' => 'Simulated device created by campusnet:demo-data.',
                    'is_enabled' => true,
                    'snmp_version' => 'v3',
                    'snmp_port' => 161,
                    'polling_interval_seconds' => 60,
                ]);
                $device->forceFill([
                    'is_demo' => true,
                    'last_status' => $online ? DeviceStatus::Online : DeviceStatus::Unreachable,
                    'last_polled_at' => $lastSeen,
                    'last_successful_poll_at' => $lastSeen,
                    'last_error' => $online ? null : '[DEMO] Simulated outage: no response from the device.',
                ])->save();
                $counts['devices']++;

                foreach ($definition['interfaces'] as $spec) {
                    $interface = new NetworkInterface([
                        'monitored_device_id' => $device->id,
                        'if_index' => $spec['index'],
                        'name' => $spec['name'],
                        'role' => $spec['role'],
                        'is_monitored' => true,
                        'oper_status' => $online ? 'up' : null,
                        'speed_mbps' => $spec['role'] === InterfaceRole::Wan ? (int) $capacity : 1000,
                    ]);
                    $interface->forceFill(['last_polled_at' => $lastSeen])->save();
                    $counts['interfaces']++;

                    $counts['samples'] += $this->createSamples(
                        $device->id,
                        $interface->id,
                        $spec['role'],
                        $spec['weight'],
                        $capacity,
                        $timezone,
                        $now,
                        $days,
                        $online ? null : $lastSeen,
                    );
                }
            }
        });

        $audit->record('demo.generated', null, $counts + ['days' => $days], 'Demo data generated.');

        $this->info("Created {$counts['devices']} demo devices, {$counts['interfaces']} interfaces and {$counts['samples']} simulated samples.");
        $this->line('All of it is marked simulated and shown under the DEMO MODE banner.');
        $this->line('Demo samples age like any other data: after the stale-data window the dashboard says the collector is offline, which is true until real polling runs.');

        return self::SUCCESS;
    }

    private function purge(AuditLogger $audit): int
    {
        $deviceIds = MonitoredDevice::withTrashed()->where('is_demo', true)->pluck('id');

        if ($deviceIds->isEmpty()) {
            $this->info('There is no demo data to remove.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Delete {$deviceIds->count()} demo devices with their interfaces, samples and alerts?")) {
            $this->line('Nothing was deleted.');

            return self::SUCCESS;
        }

        $counts = [];

        DB::transaction(function () use ($deviceIds, &$counts): void {
            $counts['samples'] = TrafficSample::whereIn('monitored_device_id', $deviceIds)->delete();
            $counts['alerts'] = NetworkAlert::whereIn('monitored_device_id', $deviceIds)->delete();
            $counts['interfaces'] = NetworkInterface::whereIn('monitored_device_id', $deviceIds)->delete();
            $counts['devices'] = MonitoredDevice::withTrashed()->whereIn('id', $deviceIds)->forceDelete();
        });

        $audit->record('demo.purged', null, $counts, 'Demo data removed.');

        $this->info("Removed {$counts['devices']} demo devices, {$counts['interfaces']} interfaces, {$counts['samples']} samples and {$counts['alerts']} alerts.");

        return self::SUCCESS;
    }

    private function createSamples(
        int $deviceId,
        int $interfaceId,
        InterfaceRole $role,
        float $weight,
        float $capacity,
        string $timezone,
        CarbonImmutable $now,
        int $days,
        ?CarbonImmutable $until,
    ): int {
        $rows = [];
        $total = 0;

        foreach ($this->timestamps($now, $days, $until) as [$at, $step]) {
            [$in, $out] = $this->traffic($role, $weight, $capacity, $at->setTimezone($timezone));
            $stamp = $at->utc()->format('Y-m-d H:i:s');

            $rows[] = [
                'monitored_device_id' => $deviceId,
                'network_interface_id' => $interfaceId,
                'sampled_at' => $stamp,
                'in_octets' => null,
                'out_octets' => null,
                'in_mbps' => $in,
                'out_mbps' => $out,
                // Utilization is measured against the internet capacity, so only WAN rows carry it.
                'utilization_percent' => $role === InterfaceRole::Wan ? Bandwidth::utilizationPercent($in, $out, $capacity) : null,
                'elapsed_seconds' => $step,
                'source' => 'mock',
                'status' => 'ok',
                'is_successful' => true,
                'error_message' => null,
                'created_at' => $stamp,
            ];

            if (count($rows) === 500) {
                DB::table('traffic_samples')->insert($rows);
                $total += count($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('traffic_samples')->insert($rows);
            $total += count($rows);
        }

        return $total;
    }

    /**
     * Five-minute samples for the history, one-minute samples for the last two hours.
     *
     * @return Generator<int, array{0: CarbonImmutable, 1: int}>
     */
    private function timestamps(CarbonImmutable $now, int $days, ?CarbonImmutable $until): Generator
    {
        $cursor = $now->subDays($days);
        $dense = $now->subHours(2);
        $end = $until ?? $now;

        while ($cursor <= $end) {
            $step = $cursor < $dense ? 300 : 60;

            yield [$cursor, $step];

            $cursor = $cursor->addSeconds($step);
        }
    }

    /**
     * @return array{0: float, 1: float} inbound and outbound Mbps
     */
    private function traffic(InterfaceRole $role, float $weight, float $capacity, CarbonImmutable $local): array
    {
        $load = DemoTraffic::loadFactor((int) $local->format('G'), (int) $local->format('N'));
        $noise = 0.85 + 0.30 * (mt_rand() / mt_getrandmax());
        $ratio = mt_rand() / mt_getrandmax();

        if ($role === InterfaceRole::Wan) {
            $in = $capacity * (0.06 + 0.84 * $load) * $noise;
            $out = $in * (0.22 + 0.15 * $ratio);
        } else {
            $in = $capacity * $weight * (0.02 + 0.40 * $load) * $noise;
            $out = $in * (0.55 + 0.25 * $ratio);
        }

        return [round(min($in, $capacity * 1.2), 4), round(min($out, $capacity * 1.2), 4)];
    }

    private function timezone(string $name): string
    {
        return in_array($name, DateTimeZone::listIdentifiers(), true) ? $name : 'UTC';
    }
}