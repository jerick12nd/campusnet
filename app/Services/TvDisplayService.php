<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TvDisplay;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TvDisplayService
{
    public const COOKIE = 'campusnet_display';

    public const PAIRING_MINUTES = 15;

    public const COOKIE_MINUTES = 60 * 24 * 365;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Create a display and a single-use pairing code. The code is returned once and never stored.
     *
     * @return array{display: TvDisplay, code: string}
     */
    public function create(string $name, User $actor): array
    {
        $code = Str::random(40);

        $display = new TvDisplay(['name' => $name, 'created_by' => $actor->id]);
        $display->forceFill([
            'pairing_hash' => hash('sha256', $code),
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
    public function redeem(string $code, ?string $ip): ?array
    {
        return DB::transaction(function () use ($code, $ip): ?array {
            $display = TvDisplay::query()
                ->where('pairing_hash', hash('sha256', $code))
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
}