<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PartConfiguration extends Model
{
    use HasFactory;
    
    protected $table = 'part_configurations';
    
    protected $fillable = [
        'address',
        'channel',
        'part_number',
        'cycle_time',
        'jumlah_bending',
        'cavity'
    ];
    
    protected $casts = [
        'channel' => 'integer',
        'cycle_time' => 'integer',
        'jumlah_bending' => 'integer',
        'cavity' => 'integer',
    ];
    
    public function inspectionTable()
    {
        return $this->belongsTo(InspectionTable::class, 'address', 'address');
    }
}
