<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DataSource;
use App\Enums\SampleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrafficSample extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'monitored_device_id',
        'network_interface_id',
        'sampled_at',
        'in_octets',
        'out_octets',
        'in_mbps',
        'out_mbps',
        'utilization_percent',
        'elapsed_seconds',
        'source',
        'status',
        'is_successful',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
            'in_octets' => 'integer',
            'out_octets' => 'integer',
            // decimal:4 keeps the database precision when serialised.
            'in_mbps' => 'decimal:4',
            'out_mbps' => 'decimal:4',
            'utilization_percent' => 'decimal:2',
            'elapsed_seconds' => 'integer',
            'source' => DataSource::class,
            'status' => SampleStatus::class,
            'is_successful' => 'boolean',
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

    /** Only samples that carry a trustworthy rate (excludes baseline, resets, failures). */
    public function scopeWithValidRate(Builder $query): Builder
    {
        return $query->where('status', SampleStatus::Ok->value);
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('sampled_at', [$from, $to]);
    }
}