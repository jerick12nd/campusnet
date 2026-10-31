<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InterfaceRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkInterface extends Model
{
    use HasFactory;

    protected $fillable = [
        'monitored_device_id',
        'if_index',
        'name',
        'description',
        'role',
        'is_monitored',
        'oper_status',
        'speed_mbps',
    ];

    protected function casts(): array
    {
        return [
            'role' => InterfaceRole::class,
            'is_monitored' => 'boolean',
            'if_index' => 'integer',
            'speed_mbps' => 'integer',
            'last_in_octets' => 'integer',
            'last_out_octets' => 'integer',
            'last_polled_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(MonitoredDevice::class, 'monitored_device_id');
    }

    public function trafficSamples(): HasMany
    {
        return $this->hasMany(TrafficSample::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(NetworkAlert::class);
    }

    public function scopeMonitored(Builder $query): Builder
    {
        return $query->where('is_monitored', true);
    }

    public function scopeWan(Builder $query): Builder
    {
        return $query->where('role', InterfaceRole::Wan->value);
    }
}