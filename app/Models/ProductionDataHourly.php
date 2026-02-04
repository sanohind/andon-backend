<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductionDataHourly extends Model
{
    use HasFactory;

    protected $table = 'production_data_hourly';

    protected $fillable = [
        'snapshot_at',
        'machine_name',
        'line_name',
        'quantity',
    ];

    protected $casts = [
        'snapshot_at' => 'datetime',
        'quantity' => 'integer',
    ];
}
