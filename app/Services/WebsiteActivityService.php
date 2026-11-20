<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ObservationType;
use App\Models\ActivityDataSource;
use App\Models\DomainActivityRecord;
use App\Models\Office;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class WebsiteActivityService
{
    public function __construct(private readonly SettingsService $settings) {}

    /** The campus time zone from settings, falling back to the application's. */
    public function timezone(): string
    {
        $timezone = (string) ($this->settings->get('timezone') ?? '');

        try {
            new DateTimeZone($timezone);

            return $timezone;
        } catch (Throwable) {
            return (string) config('app.timezone');
        }
    }

    /** @return array{from: string, to: string} the last seven days, in campus time */
    public function defaultRange(): array
    {
        $today = CarbonImmutable::now($this->timezone())->startOfDay();

        return ['from' => $today->subDays(6)->format('Y-m-d'), 'to' => $today->format('Y-m-d')];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function withDefaultRange(array $filters): array
    {
        $default = $this->defaultRange();

        $filters['to'] = $filters['to'] ?? $default['to'];
        $filters['from'] = $filters['from']
            ?? CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], $this->timezone())->subDays(6)->format('Y-m-d');

        return $filters;
    }

    /** @param array<string, mixed> $filters */
    public function query(array $filters): Builder
    {
        [$start, $end] = $this->bounds($filters['from'], $filters['to']);

        return DomainActivityRecord::query()
            ->where('observed_at', '>=', $start)
            ->where('observed_at', '<=', $end)
            ->when($filters['search'] ?? null, fn (Builder $q, string $term) => $q->where(fn (Builder $w) => $w
                ->where('domain', 'like', "%{$term}%")
                ->orWhere('base_domain', 'like', "%{$term}%")))
            ->when($filters['office'] ?? null, fn (Builder $q, int $office) => $q->where('office_id', $office))
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('observation_type', $type))
            ->when($filters['member'] ?? null, fn (Builder $q, int $member) => $q->where('member_id', $member));
    }

    /** @return array<string, \Closure> */
    public function relations(bool $memberLevel): array
    {
        $relations = [
            'office' => fn ($q) => $q->withTrashed()->select('id', 'name'),
            'source' => fn ($q) => $q->select('id', 'name'),
        ];

        if ($memberLevel) {
            $relations['member'] = fn ($q) => $q->withTrashed()->select('id', 'full_name');
            $relations['registeredDevice'] = fn ($q) => $q->withTrashed()->select('id', 'name');
        }

        return $relations;
    }

    /** @return array<string, mixed> */
    public function present(DomainActivityRecord $record, bool $memberLevel): array
    {
        $row = [
            'id' => $record->id,
            'domain' => $record->domain,
            'base_domain' => $record->base_domain,
            'observation_type' => $record->observation_type->value,
            'observation_label' => $record->observation_type->label(),
            'office_name' => $record->office?->name,
            'hits' => (int) $record->hit_count,
            'traffic_bytes' => $record->traffic_bytes !== null ? (int) $record->traffic_bytes : null,
            'source_name' => $record->source?->name,
            'observed_at' => $record->observed_at->toIso8601String(),
        ];

        // Who did it is only ever sent to people allowed to see it.
        if ($memberLevel) {
            $row['member_name'] = $record->member?->full_name;
            $row['device_name'] = $record->registeredDevice?->name;
            $row['client_ip'] = $record->client_ip;
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{hits: int, domains: int, records: int}
     */
    public function summary(array $filters): array
    {
        $row = $this->query($filters)->toBase()
            ->selectRaw('coalesce(sum(hit_count), 0) as hits, count(distinct base_domain) as domains, count(*) as records')
            ->first();

        return ['hits' => (int) $row->hits, 'domains' => (int) $row->domains, 'records' => (int) $row->records];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{domain: string, hits: int, clients: int}>
     */
    public function topDomains(array $filters): array
    {
        return $this->query($filters)->toBase()
            ->whereNotNull('base_domain')
            ->selectRaw('base_domain, sum(hit_count) as hits, count(distinct client_ip) as clients')
            ->groupBy('base_domain')
            ->orderByDesc('hits')
            ->orderBy('base_domain')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['domain' => $row->base_domain, 'hits' => (int) $row->hits, 'clients' => (int) $row->clients])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{office_id: ?int, name: string, hits: int, domains: int}>
     */
    public function byOffice(array $filters): array
    {
        $rows = $this->query($filters)->toBase()
            ->selectRaw('office_id, sum(hit_count) as hits, count(distinct base_domain) as domains')
            ->groupBy('office_id')
            ->orderByDesc('hits')
            ->limit(10)
            ->get();

        $names = Office::withTrashed()->whereIn('id', $rows->pluck('office_id')->filter()->all())->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'office_id' => $row->office_id !== null ? (int) $row->office_id : null,
            'name' => $row->office_id === null ? 'Unidentified devices' : ($names[$row->office_id] ?? 'Archived office'),
            'hits' => (int) $row->hits,
            'domains' => (int) $row->domains,
        ])->values()->all();
    }

    /**
     * Hits per day in campus time, with empty days filled in.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{day: string, hits: int}>
     */
    public function trend(array $filters): array
    {
        $shift = $this->shiftSeconds();
        $day = DB::connection()->getDriverName() === 'sqlite'
            ? sprintf("date(observed_at, '%+d seconds')", $shift)
            : sprintf('date(date_add(observed_at, interval %d second))', $shift);

        $hits = $this->query($filters)->toBase()
            ->selectRaw("{$day} as bucket_day, sum(hit_count) as hits")
            ->groupBy('bucket_day')
            ->orderBy('bucket_day')
            ->get()
            ->pluck('hits', 'bucket_day');

        $timezone = $this->timezone();
        $cursor = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], $timezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], $timezone);
        $days = [];

        while ($cursor->lte($end) && count($days) <= (int) config('office_network.max_report_days')) {
            $key = $cursor->format('Y-m-d');
            $days[] = ['day' => $key, 'hits' => (int) ($hits[$key] ?? 0)];
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    /** @return list<array<string, mixed>> the sources that can produce domain activity, with their state */
    public function sources(): array
    {
        $labels = [
            'dns' => ObservationType::DnsQuery->label(),
            'proxy' => ObservationType::ProxyRequest->label(),
            'firewall' => ObservationType::FirewallConnection->label(),
        ];

        return ActivityDataSource::query()
            ->whereIn('type', array_keys($labels))
            ->orderBy('name')
            ->get()
            ->map(fn (ActivityDataSource $source) => [
                'id' => $source->id,
                'name' => $source->name,
                'type' => $source->type,
                'type_label' => $labels[$source->type],
                'is_enabled' => $source->is_enabled,
                'retention_days' => $source->retention_days,
                'last_run_at' => $source->last_run_at?->toIso8601String(),
                'last_status' => $source->last_status,
            ])
            ->values()
            ->all();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} campus-time day boundaries, in the database time zone */
    private function bounds(string $from, string $to): array
    {
        $timezone = $this->timezone();
        $app = (string) config('app.timezone');

        return [
            CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone)->startOfDay()->setTimezone($app),
            CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone)->endOfDay()->setTimezone($app),
        ];
    }

    /** Seconds between the database time zone and the campus time zone. */
    private function shiftSeconds(): int
    {
        $now = new DateTimeImmutable('now');

        return (new DateTimeZone($this->timezone()))->getOffset($now)
            - (new DateTimeZone((string) config('app.timezone')))->getOffset($now);
    }
}