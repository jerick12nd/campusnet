<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AuditLogger
{
    public const REDACTED = '[REDACTED]';

    /**
     * Record an auditable action.
     *
     * @param  array<string, mixed>  $metadata  Non-secret context only. Keys that
     *                                          look like secrets are redacted anyway.
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $metadata = [],
        ?string $description = null,
        ?User $actor = null,
    ): ?AuditLog {
        try {
            $actor ??= Auth::user();
            $request = app()->bound('request') ? request() : null;

            return AuditLog::create([
                'user_id' => $actor?->getKey(),
                'actor_name' => $actor?->name,
                'action' => $action,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'description' => $description ? Str::limit($description, 250, '') : null,
                'metadata' => $metadata === [] ? null : $this->redact($metadata),
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent() ? Str::limit($request->userAgent(), 250, '') : null,
            ]);
        } catch (Throwable $e) {
            // Auditing must never break the user's action, but a failure must
            // not be silent either. Log the reason (no metadata, no secrets).
            Log::error('Audit log write failed', [
                'action' => $action,
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * Replace the value of any key that looks like a secret, at any depth.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redact(array $data): array
    {
        $fragments = array_map('strtolower', (array) config('campusnet.redact_keys', []));

        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->looksSecret($key, $fragments)) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            $clean[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $clean;
    }

    /**
     * @param  list<string>  $fragments
     */
    private function looksSecret(string $key, array $fragments): bool
    {
        $key = strtolower($key);

        foreach ($fragments as $fragment) {
            if ($fragment !== '' && str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}