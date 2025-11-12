<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InspectionTable extends Model
{
    use HasFactory;
    protected $table = 'inspection_tables';
	protected $fillable = ['name', 'line_name', 'division', 'address', 'oee', 'target_quantity', 'cycle_time', 'warning_cycle_count', 'problem_cycle_count'];
    
    protected $casts = [
        'target_quantity' => 'integer',
        'cycle_time' => 'integer',
        'oee' => 'decimal:2',
        'warning_cycle_count' => 'integer',
        'problem_cycle_count' => 'integer',
    ];
}
