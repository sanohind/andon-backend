<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PartConfiguration extends Model
{
    use HasFactory;

    protected $table = 'part_configurations';

    protected $fillable = [
        'part_number',
        'part_name',
        'channel',
        'line_name',
        'cycle_time',
    ];

    protected $casts = [
        'channel' => 'integer',
        'cycle_time' => 'integer',
    ];
}
