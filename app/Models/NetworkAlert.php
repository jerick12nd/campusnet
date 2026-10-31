<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'severity',
        'status',
        'title',
        'description',
        'monitored_device_id',
        'network_interface_id',
        'dedup_key',
        'metadata',
        'triggered_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'severity' => AlertSeverity::class,
            'status' => AlertStatus::class,
            'metadata' => 'array',
            'triggered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(MonitoredDevice::class, 'monitored_device_id');
    }

    public function networkInterface(): BelongsTo
    {
        return $this->belongsTo(NetworkInterface::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** Not yet resolved (active or acknowledged). */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AlertStatus::Active->value,
            AlertStatus::Acknowledged->value,
        ]);
    }

    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('severity', AlertSeverity::Critical->value);
    }
}