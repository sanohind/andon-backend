<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionOeeSnapshotFiveMinute extends Model
{
    protected $table = 'production_oee_snapshots_five_minute';

    protected $fillable = [
        'snapshot_at',
        'machine_name',
        'line_name',
        'division',
        'shot_quantity',
        'total_product',
        'ideal_quantity',
        'oee_percent',
        'availability_percent',
        'performance_percent',
        'quality_percent',
        'runtime_seconds',
        'running_hour_seconds',
    ];

    protected $casts = [
        'snapshot_at' => 'datetime',
        'shot_quantity' => 'integer',
        'total_product' => 'integer',
        'ideal_quantity' => 'integer',
        'oee_percent' => 'float',
        'availability_percent' => 'float',
        'performance_percent' => 'float',
        'quality_percent' => 'float',
        'runtime_seconds' => 'integer',
        'running_hour_seconds' => 'integer',
    ];
}
