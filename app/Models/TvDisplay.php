<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TvDisplay extends Model
{
    protected $fillable = ['name', 'created_by'];

    protected $hidden = ['pairing_hash', 'token_hash'];

    protected function casts(): array
    {
        return [
            'pairing_expires_at' => 'datetime',
            'paired_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** revoked | paired | waiting (link not used yet) | expired (link unused and too old) */
    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->token_hash !== null => 'paired',
            $this->pairing_expires_at?->isFuture() === true => 'waiting',
            default => 'expired',
        };
    }
}