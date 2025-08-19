<?php
// File: app/Models/Log.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Log extends Model
{
    // Nama tabel
    protected $table = 'log';
    
    // Tidak menggunakan timestamps Laravel default
    public $timestamps = false;
    
    // Kolom yang bisa diisi
    protected $fillable = [
        'timestamp',
        'tipe_mesin', 
        'tipe_problem',
        'status',
        'resolved_at', 
        'duration_in_seconds'
    ];
    
    // Cast tipe data
    protected $casts = [
    'resolved_at' => 'datetime',
    ];

    /**
     * Scope untuk problem yang aktif
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'ON');
    }

    /**
     * Scope untuk mesin tertentu
     */
    public function scopeForMachine($query, $machine)
    {
        return $query->where('tipe_mesin', $machine);
    }

    /**
     * Scope untuk problem baru (10 detik terakhir)
     */
    public function scopeRecent($query, $seconds = 10)
    {
        return $query->where('timestamp', '>=', Carbon::now()->subSeconds($seconds));
    }

    /**
     * Get formatted timestamp
     */
    public function getFormattedTimestampAttribute()
    {
        return Carbon::parse($this->timestamp)->format('d/m/Y H:i:s');
    }

    /**
     * Get duration since problem started
     */
    public function getDurationAttribute()
    {
    return Carbon::createFromFormat('Y-m-d H:i:s', $this->timestamp, 'UTC')->diffForHumans();
    }

    /**
     * Get problem severity
     */
    public function getSeverityAttribute()
    {
        $severityMap = [
            'Quality' => 'high',
            'Material' => 'medium', 
            'Machine' => 'critical'
        ];

        return $severityMap[$this->tipe_problem] ?? 'medium';
    }

    /**
     * Static method untuk get machine status
     */
    public static function getMachineStatus($machine)
    {
        $activeProblem = self::active()
            ->forMachine($machine)
            ->orderBy('timestamp', 'desc')
            ->first();

        return [
            'has_problem' => !is_null($activeProblem),
            'problem' => $activeProblem,
            'status' => $activeProblem ? 'problem' : 'normal',
            'color' => $activeProblem ? 'red' : 'green'
        ];
    }

    /**
     * Static method untuk get new problems
     */
    public static function getNewProblems($seconds = 10)
    {
        return self::active()
            ->recent($seconds)
            ->orderBy('timestamp', 'desc')
            ->get();
    }
}