<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TvDisplay;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class TvDisplayService
{
    public const COOKIE = 'campusnet_display';

    public const PAIRING_MINUTES = 15;

    public const COOKIE_MINUTES = 60 * 24 * 365;

    public const CODE_LENGTH = 8;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Create a display and a single-use numeric pairing code. The code is returned once and never stored.
     *
     * @return array{display: TvDisplay, code: string}
     */
    public function create(string $name, User $actor): array
    {
        $code = $this->newCode();

        $display = new TvDisplay(['name' => $name, 'created_by' => $actor->id]);
        $display->forceFill([
            'pairing_hash' => $this->hashCode($code),
            'pairing_expires_at' => now()->addMinutes(self::PAIRING_MINUTES),
        ])->save();

        $this->audit->record('tv_display.created', $display, ['name' => $name], "Created TV display {$name}", $actor);

        return ['display' => $display, 'code' => $code];
    }

    /**
     * Exchange a valid pairing code for a long-lived display token. The code can be used only once.
     *
     * @return array{display: TvDisplay, token: string}|null
     */
    public function redeem(string $input, ?string $ip): ?array
    {
        // Accept "1234 5678", "1234-5678" and "12345678".
        $code = preg_replace('/\D+/', '', $input) ?? '';

        if (strlen($code) !== self::CODE_LENGTH) {
            return null;
        }

        $paired = DB::transaction(function () use ($code, $ip): ?array {
            $display = TvDisplay::query()
                ->where('pairing_hash', $this->hashCode($code))
                ->whereNull('revoked_at')
                ->where('pairing_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($display === null) {
                return null;
            }

            $token = Str::random(64);

            $display->forceFill([
                'pairing_hash' => null,
                'pairing_expires_at' => null,
                'token_hash' => hash('sha256', $token),
                'paired_at' => now(),
                'last_seen_at' => now(),
                'last_seen_ip' => $ip,
            ])->save();

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
            'pairing_expires_at' => null,
        ])->save();

        $this->audit->record('tv_display.revoked', $display, [], "Revoked TV display {$display->name}", $actor);
    }

    /** Random 8-digit code that no other unused code is using. */
    private function newCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 99_999_999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        } while (TvDisplay::query()->where('pairing_hash', $this->hashCode($code))->exists());

        return $code;
    }

    /** Keyed hash: a short numeric code cannot be brute-forced offline without APP_KEY. */
    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}