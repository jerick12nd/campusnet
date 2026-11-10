<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScanRun extends Model
{
    public $timestamps = false;

    protected $fillable = ['office', 'cidr', 'hosts_up', 'duration_ms', 'scanned_at'];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime'];
    }

    public function hosts(): HasMany
    {
        return $this->hasMany(ScanHost::class);
    }
}