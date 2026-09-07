<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionNgData extends Model
{
    protected $table = 'production_ng_data';

    protected $fillable = [
        'machine_name',
        'line_name',
        'shift_key',
        'ng_qty',
        'snapshot_at',
    ];

    protected $casts = [
        'ng_qty' => 'integer',
        'snapshot_at' => 'datetime',
    ];
}

