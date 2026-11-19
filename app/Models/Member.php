<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use SoftDeletes;

    protected $fillable = ['office_id', 'full_name', 'employee_id', 'position', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(MemberDeviceAssignment::class);
    }

    /** Devices currently assigned to this member. */
    public function activeDevices(): BelongsToMany
    {
        return $this->belongsToMany(RegisteredDevice::class, 'member_device_assignments')
            ->wherePivotNull('unassigned_at')
            ->withPivot('assigned_at');
    }
}