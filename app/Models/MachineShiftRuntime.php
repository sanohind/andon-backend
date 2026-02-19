<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineShiftRuntime extends Model
{
    protected $table = 'machine_shift_runtime';

    protected $fillable = [
        'address',
        'shift_key',
        'runtime_seconds_accumulated',
        'runtime_pause_started_at',
        'last_resume_at',
        'downtime_started_at',
    ];

    protected $casts = [
        'runtime_seconds_accumulated' => 'integer',
        'runtime_pause_started_at' => 'datetime',
        'last_resume_at' => 'datetime',
        'downtime_started_at' => 'datetime',
    ];
}
