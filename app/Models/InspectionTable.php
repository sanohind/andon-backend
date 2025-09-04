<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InspectionTable extends Model
{
    use HasFactory;
    protected $table = 'inspection_tables';
    protected $fillable = ['name', 'line_number'];
}
