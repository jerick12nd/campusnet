<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TvDisplay;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class TvDisplayService
{
    public const COOKIE = 'campusnet_display';

    public const PAIRING_MINUTES = 15;

    public const COOKIE_MINUTES = 60 * 24 * 365;

    /** One-time codes have 8 digits; reusable codes have 10. */
    public const CODE_LENGTH = 8;

    public const REUSABLE_CODE_LENGTH = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Create a display and a numeric pairing code.
     * One-time codes expire after 15 minutes and are never stored. Reusable codes do not expire.
     *
     * @return array{display: TvDisplay, code: string}
     */
    public function create(string $name, User $actor, bool $reusable = false): array
    {
        $code = $this->newCode($reusable);

        $display = new TvDisplay(['name' => $name, 'created_by' => $actor->id]);
        $display->forceFill([
            'is_reusable' => $reusable,
            'pairing_hash' => $this->hashCode($code),
            'pairing_code' => $reusable ? Crypt::encryptString($code) : null,
            'pairing_expires_at' => $reusable ? null : now()->addMinutes(self::PAIRING_MINUTES),
        ])->save();

        $this->audit->record(
            'tv_display.created',
            $display,
            ['name' => $name, 'reusable' => $reusable],
            "Created TV display {$name}".($reusable ? ' (reusable code)' : ''),
            $actor,
        );

        return ['display' => $display, 'code' => $code];
    }

    /**
     * Exchange a valid pairing code for a long-lived display token.
     * A one-time code is used up. A reusable code stays valid, and pairing again replaces the
     * previous device's token, so a display has only one active device at a time.
     *
     * @return array{display: TvDisplay, token: string}|null
     */
    public function redeem(string $input, ?string $ip): ?array
    {
        // Accept "1234 5678", "1234-5678" and "12345678".
        $code = preg_replace('/\D+/', '', $input) ?? '';
        $length = strlen($code);

        if ($length < self::CODE_LENGTH || $length > self::REUSABLE_CODE_LENGTH) {
            return null;
        }

        $paired = DB::transaction(function () use ($code, $ip): ?array {
            $display = TvDisplay::query()
                ->where('pairing_hash', $this->hashCode($code))
                ->whereNull('revoked_at')
                ->where(function ($query): void {
                    $query->where('is_reusable', true)
                        ->orWhere('pairing_expires_at', '>', now());
                })
                ->lockForUpdate()
                ->first();

            if ($display === null) {
                return null;
            }

            $token = Str::random(64);

            $attributes = [
                'token_hash' => hash('sha256', $token),
                'paired_at' => now(),
                'last_seen_at' => now(),
                'last_seen_ip' => $ip,
            ];

            if (! $display->is_reusable) {
                $attributes['pairing_hash'] = null;
                $attributes['pairing_expires_at'] = null;
            }

            $display->forceFill($attributes)->save();

            $this->audit->record('tv_display.paired', $display, [], "TV display {$display->name} was paired");

            return ['display' => $display, 'token' => $token];
        });

        if ($paired === null) {
            // The attempted code is never logged, only that a wrong one was tried.
            Log::warning('TV display pairing code rejected', ['ip' => $ip]);
        }

        return $paired;
    }

    public function authenticate(string $token): ?TvDisplay
    {
        $display = TvDisplay::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();

        // Record activity at most once a minute so polling does not write on every request.
        if ($display !== null && ($display->last_seen_at === null || $display->last_seen_at->lt(now()->subMinute()))) {
            $display->forceFill(['last_seen_at' => now(), 'last_seen_ip' => request()->ip()])->saveQuietly();
        }

        return $display;
    }

    public function revoke(TvDisplay $display, User $actor): void
    {
        $display->forceFill([
            'revoked_at' => now(),
            'token_hash' => null,
            'pairing_hash' => null,
            'pairing_code' => null,
            'pairing_expires_at' => null,
        ])->save();

        $this->audit->record('tv_display.revoked', $display, [], "Revoked TV display {$display->name}", $actor);
    }

    /** Random numeric code that no other code is using. */
    private function newCode(bool $reusable): string
    {
        $length = $reusable ? self::REUSABLE_CODE_LENGTH : self::CODE_LENGTH;

        do {
            $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
        } while (TvDisplay::query()->where('pairing_hash', $this->hashCode($code))->exists());

        return $code;
    }

    /** Keyed hash: a numeric code cannot be brute-forced offline without APP_KEY. */
    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}