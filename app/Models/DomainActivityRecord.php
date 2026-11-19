<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ObservationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainActivityRecord extends Model
{
    protected $fillable = [
        'registered_device_id', 'member_id', 'office_id', 'activity_data_source_id',
        'domain', 'observation_type', 'client_ip', 'hit_count', 'traffic_bytes', 'observed_at',
    ];

    protected function casts(): array
    {
        return [
            'observation_type' => ObservationType::class,
            'observed_at' => 'datetime',
        ];
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