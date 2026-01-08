<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeknisiPic extends Model
{
    protected $table = 'teknisi_pic';
    
    protected $fillable = [
        'nama',
        'departement'
    ];
}
