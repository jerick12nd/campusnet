<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfficeNetwork extends Model
{
    protected $fillable = ['office', 'cidr'];
}