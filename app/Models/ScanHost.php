<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScanHost extends Model
{
    public $timestamps = false;

    protected $fillable = ['scan_run_id', 'ip', 'mac', 'vendor', 'hostname', 'random_mac'];

    protected function casts(): array
    {
        return ['random_mac' => 'boolean'];
    }
}