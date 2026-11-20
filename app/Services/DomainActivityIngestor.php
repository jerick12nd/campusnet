<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ObservationType;
use App\Models\ActivityDataSource;
use App\Models\DeviceConnectionSession;
use App\Models\DomainActivityRecord;
use App\Support\DomainName;
use App\Support\MacAddress;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns raw observations from a collector into stored domain records.
 *
 * A record is linked to a device and member only when a connection session proves that the
 * client IP belonged to a registered device at that moment. Otherwise it is stored as
 * unidentified. Nothing is ever guessed.
 */
class DomainActivityIngestor
{
    private const TYPES = [
        'dns' => ObservationType::DnsQuery,
        'proxy' => ObservationType::ProxyRequest,
        'firewall' => ObservationType::FirewallConnection,
    ];

    /**
     * @param  iterable<array{client_ip: string, domain: string, observed_at: mixed, mac?: ?string, traffic_bytes?: ?int}>  $observations
     * @return array{received: int, invalid: int, stored: int, attributed: int, unidentified: int}
     */
    public function ingest(ActivityDataSource $source, iterable $observations): array
    {
        $type = self::TYPES[$source->type] ?? null;

        if ($type === null) {
            throw new DomainException("A {$source->type} source does not produce domain activity.");
        }

        if (! $source->is_enabled) {
            throw new DomainException('This data source is disabled.');
        }

        $stats = ['received' => 0, 'invalid' => 0, 'stored' => 0, 'attributed' => 0, 'unidentified' => 0];
        $items = [];

        foreach ($observations as $observation) {
            $stats['received']++;

            $domain = DomainName::normalize($observation['domain'] ?? null);
            $ip = $observation['client_ip'] ?? null;
            $at = $this->time($observation['observed_at'] ?? null);

            if ($domain === null || ! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false || $at === null) {
                $stats['invalid']++;

                continue;
            }

            $items[] = [
                'domain' => $domain,
                'ip' => $ip,
                'at' => $at,
                'mac' => MacAddress::normalize($observation['mac'] ?? null),
                'bytes' => isset($observation['traffic_bytes']) ? max(0, (int) $observation['traffic_bytes']) : null,
            ];
        }

        if ($items === []) {
            return $stats;
        }

        $sessions = $this->sessionsFor($items);
        $grace = (int) config('office_network.online_threshold_seconds');
        $bucketSeconds = max(60, (int) config('office_network.domain_bucket_seconds'));
        $groups = [];

        foreach ($items as $item) {
            $session = $this->attribute($item, $sessions, $grace);
            $identified = $session !== null && $session->registered_device_id !== null;

            $identified ? $stats['attributed']++ : $stats['unidentified']++;

            $bucket = intdiv($item['at']->getTimestamp(), $bucketSeconds) * $bucketSeconds;
            $device = $identified ? $session->registered_device_id : null;
            $key = implode('|', [$device ?? '-', $item['ip'], $item['domain'], $bucket]);

            $groups[$key] ??= [
                'device' => $device,
                'member' => $identified ? $session->member_id : null,
                'office' => $identified ? $session->office_id : null,
                'ip' => $item['ip'],
                'domain' => $item['domain'],
                'bucket' => $bucket,
                'hits' => 0,
                'bytes' => null,
            ];

            $groups[$key]['hits']++;

            if ($item['bytes'] !== null) {
                $groups[$key]['bytes'] = ($groups[$key]['bytes'] ?? 0) + $item['bytes'];
            }
        }

        DB::transaction(function () use ($groups, $source, $type, &$stats): void {
            foreach ($groups as $group) {
                $observedAt = CarbonImmutable::createFromTimestamp($group['bucket'], (string) config('app.timezone'));

                $existing = DomainActivityRecord::query()
                    ->where('activity_data_source_id', $source->id)
                    ->where('client_ip', $group['ip'])
                    ->where('domain', $group['domain'])
                    ->where('observed_at', $observedAt)
                    ->where('registered_device_id', $group['device'])
                    ->first();

                if ($existing !== null) {
                    $existing->hit_count += $group['hits'];

                    if ($group['bytes'] !== null) {
                        $existing->traffic_bytes = ((int) $existing->traffic_bytes) + $group['bytes'];
                    }

                    $existing->save();
                } else {
                    DomainActivityRecord::create([
                        'registered_device_id' => $group['device'],
                        'member_id' => $group['member'],
                        'office_id' => $group['office'],
                        'activity_data_source_id' => $source->id,
                        'domain' => $group['domain'],
                        'base_domain' => DomainName::base($group['domain']),
                        'observation_type' => $type,
                        'client_ip' => $group['ip'],
                        'hit_count' => $group['hits'],
                        'traffic_bytes' => $group['bytes'],
                        'observed_at' => $observedAt,
                    ]);
                }

                $stats['stored']++;
            }
        });

        return $stats;
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        try {
            $time = $value instanceof CarbonInterface
                ? CarbonImmutable::instance($value)
                : (($value === null || $value === '') ? null : CarbonImmutable::parse($value));
        } catch (Throwable) {
            return null;
        }

        if ($time === null || $time->gt(now()->addMinutes(5))) {
            return null;
        }

        return $time->setTimezone((string) config('app.timezone'));
    }

    /**
     * Connection sessions that could cover these observations, grouped by IP address.
     *
     * @param  list<array<string, mixed>>  $items
     * @return Collection<string, Collection<int, DeviceConnectionSession>>
     */
    private function sessionsFor(array $items): Collection
    {
        $timezone = (string) config('app.timezone');
        $stamps = array_map(fn (array $item) => $item['at']->getTimestamp(), $items);
        $min = CarbonImmutable::createFromTimestamp(min($stamps), $timezone);
        $max = CarbonImmutable::createFromTimestamp(max($stamps), $timezone);

        return DeviceConnectionSession::query()
            ->whereIn('ip_address', array_values(array_unique(array_column($items, 'ip'))))
            ->where('started_at', '<=', $max)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $min))
            ->get(['id', 'registered_device_id', 'member_id', 'office_id', 'mac_address', 'ip_address', 'started_at', 'last_seen_at', 'ended_at'])
            ->groupBy('ip_address');
    }

    /**
     * The session that owned this IP at this moment. An open session only counts while it was
     * still being seen, so a collector that stopped does not attribute new traffic to old leases.
     *
     * @param  array<string, mixed>  $item
     * @param  Collection<string, Collection<int, DeviceConnectionSession>>  $sessions
     */
    private function attribute(array $item, Collection $sessions, int $grace): ?DeviceConnectionSession
    {
        return ($sessions->get($item['ip']) ?? collect())
            ->filter(function (DeviceConnectionSession $session) use ($item, $grace): bool {
                if ($item['mac'] !== null && $session->mac_address !== $item['mac']) {
                    return false;
                }

                if ($session->started_at->gt($item['at'])) {
                    return false;
                }

                $end = $session->ended_at ?? $session->last_seen_at->copy()->addSeconds($grace);

                return $item['at']->lte($end);
            })
            ->sortByDesc(fn (DeviceConnectionSession $session) => $session->started_at->getTimestamp())
            ->first();
    }
}