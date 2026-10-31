<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MonitoringSetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Typed access to monitoring_settings with config('campusnet.defaults')
 * as the fallback. The settings screen (a later phase) writes through put().
 */
class SettingsService
{
    private const CACHE_KEY = 'campusnet.settings';

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $defaults = (array) config('campusnet.defaults');

        try {
            $stored = Cache::remember(
                self::CACHE_KEY,
                now()->addMinutes(10),
                fn (): array => MonitoringSetting::query()->pluck('value', 'key')->all(),
            );
        } catch (QueryException $e) {
            // Table missing (migrations not run yet) or database down: fall back to
            // config defaults so the login page still renders, and say why in the log.
            Log::warning('Monitoring settings unavailable, using defaults.', [
                'exception' => $e::class,
            ]);

            $stored = [];
        }

        $merged = $defaults;
        foreach ($defaults as $key => $default) {
            if (array_key_exists($key, $stored) && $stored[$key] !== null) {
                $merged[$key] = $this->cast($stored[$key], $default);
            }
        }

        return $merged;
    }

    public function get(string $key): mixed
    {
        $all = $this->all();

        if (! array_key_exists($key, $all)) {
            throw new InvalidArgumentException("Unknown setting [{$key}].");
        }

        return $all[$key];
    }

    public function put(string $key, mixed $value, ?User $by = null): void
    {
        if (! array_key_exists($key, (array) config('campusnet.defaults'))) {
            throw new InvalidArgumentException("Unknown setting [{$key}].");
        }

        MonitoringSetting::updateOrCreate(
            ['key' => $key],
            ['value' => is_bool($value) ? (string) (int) $value : (string) $value, 'updated_by' => $by?->getKey()],
        );

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function cast(string $stored, mixed $default): mixed
    {
        return match (true) {
            is_int($default) => (int) $stored,
            is_float($default) => (float) $stored,
            is_bool($default) => (bool) (int) $stored,
            default => $stored,
        };
    }
}