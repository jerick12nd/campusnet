<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Edits the CampusNet settings that live in monitoring_settings. Only a changed value is
 * written, and every change is recorded in the audit log with the old and the new value.
 */
final class SystemSettingsService
{
    /**
     * The settings an administrator may edit. Retention days are deliberately not here:
     * they come from .env, because deleting history should not be one click away.
     *
     * @var list<string>
     */
    public const KEYS = [
        'campus_name',
        'timezone',
        'bandwidth_capacity_mbps',
        'warning_threshold_percent',
        'critical_threshold_percent',
        'dashboard_refresh_seconds',
        'stale_data_minutes',
        'monitoring_interval_seconds',
        'polling_timeout_seconds',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function current(): array
    {
        return array_intersect_key($this->settings->all(), array_flip(self::KEYS));
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return array_intersect_key((array) config('campusnet.defaults'), array_flip(self::KEYS));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, array{from: mixed, to: mixed}> the settings that changed
     */
    public function update(array $input, User $actor): array
    {
        $current = $this->current();
        $defaults = $this->defaults();
        $changes = [];

        DB::transaction(function () use ($input, $current, $defaults, $actor, &$changes): void {
            foreach (self::KEYS as $key) {
                if (! array_key_exists($key, $input)) {
                    continue;
                }

                $value = $this->cast($input[$key], $defaults[$key]);

                if ($value === $current[$key]) {
                    continue;
                }

                $this->settings->put($key, $value, $actor);
                $changes[$key] = ['from' => $current[$key], 'to' => $value];
            }

            if ($changes !== []) {
                $this->audit->record(
                    'settings.updated',
                    null,
                    ['changes' => $changes],
                    'System settings changed: '.implode(', ', array_keys($changes)).'.',
                    $actor,
                );
            }
        });

        return $changes;
    }

    private function cast(mixed $value, mixed $default): mixed
    {
        return match (true) {
            is_int($default) => (int) $value,
            is_float($default) => (float) $value,
            is_bool($default) => (bool) $value,
            default => trim((string) $value),
        };
    }
}