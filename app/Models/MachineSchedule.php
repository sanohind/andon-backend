<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineSchedule extends Model
{
    protected $table = 'machine_schedules';

    protected $fillable = [
        'schedule_date',
        'machine_address',
        'shift',
        'target_quantity',
        'ot_enabled',
        'ot_duration_type',
        'target_ot',
        'status',
    ];

    protected $casts = [
        'schedule_date' => 'date',
        'target_quantity' => 'integer',
        'target_ot' => 'integer',
    ];

    /**
     * Ensure ot_enabled is stored in a way PostgreSQL boolean accepts.
     */
    public function setOtEnabledAttribute($value): void
    {
        $this->attributes['ot_enabled'] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }
}
