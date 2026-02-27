<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OeeSetting extends Model
{
    protected $table = 'oee_settings';

    protected $fillable = [
        'warning_threshold_percent',
    ];
}

