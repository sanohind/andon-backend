<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionDowntimeRecord extends Model
{
    protected $table = 'production_downtime_records';

    protected $fillable = [
        'mongo_id',
        'machine_name',
        'line_process_id',
        'line_process_code',
        'line_process_name',
        'cause',
        'start_at',
        'end_at',
        'duration_seconds',
        'is_closed',
        'shift_key',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'duration_seconds' => 'integer',
        'is_closed' => 'boolean',
    ];
}

