<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityDataSource extends Model
{
    protected $fillable = [
        'key', 'name', 'type', 'description', 'monitored_device_id',
        'is_enabled', 'retention_days', 'settings',
        'last_run_at', 'last_status', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'settings' => 'array',
            'last_run_at' => 'datetime',
        ];
    }

    public function monitoredDevice(): BelongsTo
    {
        return $this->belongsTo(MonitoredDevice::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }
}