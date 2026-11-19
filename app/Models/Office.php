<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Office extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'code', 'department', 'subnet_cidr', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function registeredDevices(): HasMany
    {
        return $this->hasMany(RegisteredDevice::class);
    }

    public function connectionSessions(): HasMany
    {
        return $this->hasMany(DeviceConnectionSession::class);
    }
}