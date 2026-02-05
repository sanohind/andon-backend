<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InspectionTable extends Model
{
    use HasFactory;
    protected $table = 'inspection_tables';
	protected $fillable = ['name', 'line_name', 'division', 'address', 'oee', 'target_quantity', 'cycle_time', 'warning_cycle_count', 'problem_cycle_count', 'ot_enabled', 'ot_duration_type', 'target_ot'];

    protected $casts = [
        'target_quantity' => 'integer',
        'cycle_time' => 'integer',
        'oee' => 'decimal:2',
        'warning_cycle_count' => 'integer',
        'problem_cycle_count' => 'integer',
        'ot_enabled' => 'boolean',
        'target_ot' => 'integer',
    ];

    /**
     * Mutator: pastikan ot_enabled selalu disimpan sebagai boolean untuk PostgreSQL.
     * Mencegah mismatch tipe (integer 1/0 -> boolean true/false).
     */
    public function setOtEnabledAttribute($value): void
    {
        $this->attributes['ot_enabled'] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? true : false;
    }
}
