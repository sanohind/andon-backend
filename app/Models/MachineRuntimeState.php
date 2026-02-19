<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineRuntimeState extends Model
{
    protected $table = 'machine_runtime_states';

    protected $fillable = [
        'machine_address',
        'shift_key',
        'downtime_start_at',
        'runtime_pause_start_at',
        'runtime_pause_accumulated_seconds',
        'paused_runtime_value',
    ];

    protected $casts = [
        'downtime_start_at' => 'datetime',
        'runtime_pause_start_at' => 'datetime',
        'runtime_pause_accumulated_seconds' => 'integer',
        'paused_runtime_value' => 'integer',
    ];
}
