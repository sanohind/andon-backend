<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineNote extends Model
{
    protected $table = 'machine_notes';

    protected $fillable = [
        'machine_name',
        'line_name',
        'shift_key',
        'shift',
        'shift_start_at',
        'note',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'shift_start_at' => 'datetime',
    ];
}

