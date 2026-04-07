<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OeeRecordHourly extends Model
{
    protected $table = 'oee_records_hourly';

    protected $fillable = [
        'snapshot_at',
        'machine_address',
        'line_name',
        'division',
        'oee_percent',
        'availability_percent',
        'performance_percent',
        'quality_percent',
        'runtime_seconds',
        'running_hour_seconds',
        'total_product',
    ];

    protected $casts = [
        'snapshot_at' => 'datetime',
        'oee_percent' => 'float',
        'availability_percent' => 'float',
        'performance_percent' => 'float',
        'quality_percent' => 'float',
        'runtime_seconds' => 'integer',
        'running_hour_seconds' => 'integer',
        'total_product' => 'integer',
    ];
}
