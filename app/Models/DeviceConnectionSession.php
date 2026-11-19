<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceConnectionSession extends Model
{
    protected $fillable = [
        'registered_device_id', 'member_id', 'office_id', 'activity_data_source_id',
        'monitored_device_id', 'mac_address', 'ip_address', 'hostname', 'interface_name',
        'started_at', 'last_seen_at', 'ended_at', 'rx_bytes', 'tx_bytes',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** Open and seen recently. This is the single definition of "online" for the module. */
    public function scopeOnline(Builder $query): Builder
    {
        return $query->whereNull('ended_at')
            ->where('last_seen_at', '>=', now()->subSeconds(config('office_network.online_threshold_seconds')));
    }

    /** Not registered, so we do not guess who it is. */
    public function scopeUnidentified(Builder $query): Builder
    {
        return $query->whereNull('registered_device_id');
    }

    public function registeredDevice(): BelongsTo
    {
        return $this->belongsTo(RegisteredDevice::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ActivityDataSource::class, 'activity_data_source_id');
    }
}