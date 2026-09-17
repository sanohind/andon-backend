<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionDowntimeData extends Model
{
    protected $table = 'production_downtime_data';

    protected $fillable = [
        'machine_name',
        'line_name',
        'shift_key',
        'downtime_seconds',
        'open_records_count',
        'snapshot_at',
    ];

    protected $casts = [
        'downtime_seconds' => 'integer',
        'open_records_count' => 'integer',
        'snapshot_at' => 'datetime',
    ];
}

