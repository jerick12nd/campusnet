<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EndpointType;
use App\Support\MacAddress;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class RegisteredDevice extends Model
{
    use SoftDeletes;

    protected $fillable = ['office_id', 'name', 'device_type', 'mac_address', 'notes', 'is_active'];

    protected function casts(): array
    {
        return [
            'device_type' => EndpointType::class,
            'is_active' => 'boolean',
        ];
    }

    /** Always store the MAC as AA:BB:CC:DD:EE:FF so lookups match what the router reports. */
    protected function macAddress(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => MacAddress::normalize($value));
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(MemberDeviceAssignment::class);
    }

    /** The open assignment (unassigned_at is null), if any. */
    public function currentAssignment(): HasOne
    {
        return $this->hasOne(MemberDeviceAssignment::class)->whereNull('unassigned_at');
    }
}