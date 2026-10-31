<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeviceStatus;
use App\Enums\DeviceType;
use App\Enums\SnmpVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MonitoredDevice extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'management_ip',
        'vendor',
        'model',
        'device_type',
        'location',
        'description',
        'is_enabled',
        'snmp_version',
        'snmp_port',
        'polling_interval_seconds',
        'credentials',
    ];

    /**
     * Credentials never leave the server: they are encrypted at rest and
     * excluded from every array / JSON / Inertia serialization.
     *
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'device_type' => DeviceType::class,
            'snmp_version' => SnmpVersion::class,
            'last_status' => DeviceStatus::class,
            'is_enabled' => 'boolean',
            'is_demo' => 'boolean',
            'snmp_port' => 'integer',
            'polling_interval_seconds' => 'integer',
            'credentials' => 'encrypted:array',
            'last_polled_at' => 'datetime',
            'last_successful_poll_at' => 'datetime',
        ];
    }

    public function interfaces(): HasMany
    {
        return $this->hasMany(NetworkInterface::class);
    }

    public function trafficSamples(): HasMany
    {
        return $this->hasMany(TrafficSample::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(NetworkAlert::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /** Safe to show in the UI: tells whether credentials exist, never what they are. */
    public function hasCredentials(): bool
    {
        return ! empty($this->credentials);
    }
}