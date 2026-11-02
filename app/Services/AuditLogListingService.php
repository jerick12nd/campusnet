<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads the audit trail. It uses the query builder on purpose: the log is append-only,
 * so there is nothing to hydrate, and the metadata was already redacted when it was written.
 */
final class AuditLogListingService
{
    public const PER_PAGE = 30;

    /**
     * @param  array{action: string, search: string, from: string, to: string}  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters, string $campusTimezone): array
    {
        $appTimezone = (string) config('app.timezone');

        $query = DB::table('audit_logs');

        $this->applyFilters($query, $filters, $campusTimezone, $appTimezone);

        $page = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $page->through(function (object $row) use ($appTimezone): array {
            $metadata = $row->metadata === null ? null : json_decode((string) $row->metadata, true);

            return [
                'id' => (int) $row->id,
                'created_at' => CarbonImmutable::parse((string) $row->created_at, $appTimezone)->toIso8601String(),
                'actor' => $row->actor_name !== null && $row->actor_name !== '' ? (string) $row->actor_name : 'System',
                'action' => (string) $row->action,
                'description' => $row->description,
                'subject' => $row->subject_type === null
                    ? null
                    : Str::afterLast((string) $row->subject_type, '\\').($row->subject_id !== null ? ' #'.$row->subject_id : ''),
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'metadata' => is_array($metadata) && $metadata !== [] ? $metadata : null,
            ];
        });

        return [
            'logs' => $page->toArray(),
            'actions' => $this->actions(),
            'stats' => $this->stats($appTimezone),
        ];
    }

    /**
     * @param  array{action: string, search: string, from: string, to: string}  $filters
     */
    private function applyFilters(Builder $query, array $filters, string $campusTimezone, string $appTimezone): void
    {
        if ($filters['action'] !== '') {
            $query->where('action', $filters['action']);
        }

        if ($filters['search'] !== '') {
            $term = '%'.$filters['search'].'%';

            $query->where(fn (Builder $inner) => $inner
                ->where('description', 'like', $term)
                ->orWhere('actor_name', 'like', $term)
                ->orWhere('action', 'like', $term)
                ->orWhere('ip_address', 'like', $term));
        }

        // Dates are calendar days in campus time, stored times are in the application time zone.
        if ($filters['from'] !== '') {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'], $campusTimezone)->startOfDay()->setTimezone($appTimezone)->format('Y-m-d H:i:s'));
        }

        if ($filters['to'] !== '') {
            $query->where('created_at', '<=', CarbonImmutable::parse($filters['to'], $campusTimezone)->endOfDay()->setTimezone($appTimezone)->format('Y-m-d H:i:s'));
        }
    }

    /**
     * @return list<string>
     */
    private function actions(): array
    {
        return DB::table('audit_logs')->distinct()->orderBy('action')->pluck('action')->map(fn ($action): string => (string) $action)->all();
    }

    /**
     * @return array{events_24h: int, failed_logins_24h: int, changes_24h: int}
     */
    private function stats(string $appTimezone): array
    {
        $since = CarbonImmutable::now($appTimezone)->subDay()->format('Y-m-d H:i:s');

        $rows = DB::table('audit_logs')
            ->where('created_at', '>=', $since)
            ->selectRaw('action, COUNT(*) as aggregate')
            ->groupBy('action')
            ->pluck('aggregate', 'action');

        $total = 0;
        $changes = 0;

        foreach ($rows as $action => $count) {
            $total += (int) $count;

            // Anything that is not a sign-in event, a prune, or a report export changed something.
            if (! Str::startsWith((string) $action, ['auth.', 'report.', 'data.'])) {
                $changes += (int) $count;
            }
        }

        return [
            'events_24h' => $total,
            'failed_logins_24h' => (int) ($rows['auth.login_failed'] ?? 0),
            'changes_24h' => $changes,
        ];
    }
}