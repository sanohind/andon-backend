<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OeeRecord extends Model
{
    protected $fillable = [
        'shift_date',
        'shift',
        'machine_address',
        'machine_name',
        'line_name',
        'division',
        'oee_percent',
        'availability_percent',
        'performance_percent',
        'quality_percent',
        'runtime_seconds',
        'running_hour_seconds',
        'total_product',
        'snapshot_at',
    ];

    protected $casts = [
        'shift_date' => 'date',
        'oee_percent' => 'float',
        'availability_percent' => 'float',
        'performance_percent' => 'float',
        'quality_percent' => 'float',
        'runtime_seconds' => 'integer',
        'running_hour_seconds' => 'integer',
        'total_product' => 'integer',
        'snapshot_at' => 'datetime',
    ];
}
