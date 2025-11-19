<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Carbon\Carbon;

class TicketingProblem extends Model
{
    protected $table = 'ticketing_problems';
    
    protected $fillable = [
        'problem_id',
        'pic_technician',
        'diagnosis',
        'result_repair',
        'problem_received_at',
        'diagnosis_started_at',
        'repair_started_at',
        'repair_completed_at',
        'downtime_seconds',
        'mttr_seconds',
        'mttd_seconds',
        'mtbf_seconds',
        'status',
        'created_by_user_id',
        'updated_by_user_id',
        'metadata'
    ];
    
    protected $casts = [
        'problem_received_at' => 'datetime:Y-m-d H:i:s',
        'diagnosis_started_at' => 'datetime:Y-m-d H:i:s',
        'repair_started_at' => 'datetime:Y-m-d H:i:s',
        'repair_completed_at' => 'datetime:Y-m-d H:i:s',
        'downtime_seconds' => 'integer',
        'mttr_seconds' => 'integer',
        'mttd_seconds' => 'integer',
        'mtbf_seconds' => 'integer',
        'metadata' => 'array'
    ];
    
    // Set timezone untuk semua kolom datetime
    protected $dates = [
        'problem_received_at',
        'diagnosis_started_at',
        'repair_started_at',
        'repair_completed_at'
    ];

    /**
     * Boot method untuk set timezone
     */
    protected static function boot()
    {
        parent::boot();
        
        // Set timezone untuk semua operasi datetime
        static::creating(function ($model) {
            $model->setTimezoneForDates();
        });
        
        static::updating(function ($model) {
            $model->setTimezoneForDates();
        });
        
        // Auto-update calculated times setelah save
        static::saved(function ($model) {
            // Hanya update jika ada perubahan pada timestamp fields
            if ($model->wasChanged([
                'problem_received_at', 
                'diagnosis_started_at', 
                'repair_started_at', 
                'repair_completed_at'
            ])) {
                $model->updateCalculatedTimes();
            }
        });
    }
    
    /**
     * Set timezone untuk semua kolom datetime
     */
    protected function setTimezoneForDates()
    {
        $dateColumns = ['problem_received_at', 'diagnosis_started_at', 'repair_started_at', 'repair_completed_at'];
        
        foreach ($dateColumns as $column) {
            if ($this->isDirty($column) && $this->$column) {
                $this->$column = Carbon::parse($this->$column, config('app.timezone'));
            }
        }
    }

    /**
     * Relationship dengan Log (problem utama)
     */
    public function problem(): BelongsTo
    {
        return $this->belongsTo(Log::class, 'problem_id');
    }
    
    /**
     * Relationship dengan User yang membuat ticketing
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
    
    /**
     * Relationship dengan User yang mengupdate terakhir
     */
    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Scope untuk ticketing berdasarkan status
     */
    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope untuk ticketing berdasarkan problem
     */
    public function scopeForProblem($query, $problemId)
    {
        return $query->where('problem_id', $problemId);
    }

    /**
     * Scope untuk ticketing berdasarkan user
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('created_by_user_id', $userId);
    }

    /**
     * Get formatted timestamps
     */
    public function getFormattedProblemReceivedAtAttribute()
    {
        return $this->problem_received_at ? Carbon::parse($this->problem_received_at)->format('d/m/Y H:i:s') : null;
    }

    public function getFormattedDiagnosisStartedAtAttribute()
    {
        return $this->diagnosis_started_at ? Carbon::parse($this->diagnosis_started_at)->format('d/m/Y H:i:s') : null;
    }

    public function getFormattedRepairStartedAtAttribute()
    {
        return $this->repair_started_at ? Carbon::parse($this->repair_started_at)->format('d/m/Y H:i:s') : null;
    }

    public function getFormattedRepairCompletedAtAttribute()
    {
        return $this->repair_completed_at ? Carbon::parse($this->repair_completed_at)->format('d/m/Y H:i:s') : null;
    }

    /**
     * Get formatted durations
     */
    public function getFormattedDowntimeAttribute()
    {
        return $this->formatDuration($this->downtime_seconds);
    }

    public function getFormattedMttrAttribute()
    {
        return $this->formatDuration($this->mttr_seconds);
    }

    public function getFormattedMttdAttribute()
    {
        return $this->formatDuration($this->mttd_seconds);
    }

    public function getFormattedMtbfAttribute()
    {
        return $this->formatDuration($this->mtbf_seconds);
    }

    /**
     * Format durasi dalam detik menjadi format yang lebih readable (konsisten dengan forward analytics)
     */
    private function formatDuration($seconds)
    {
        // Handle null or negative values
        if ($seconds === null || $seconds <= 0) {
            return '-';
        }
        
        $minutes = round($seconds / 60);
        
        if ($minutes < 1) {
            return '< 1 menit';
        } elseif ($minutes < 60) {
            return $minutes . ' menit';
        } else {
            $hours = floor($minutes / 60);
            $remainingMinutes = $minutes % 60;
            if ($remainingMinutes == 0) {
                return $hours . ' jam';
            } else {
                return $hours . ' jam ' . $remainingMinutes . ' menit';
            }
        }
    }

    /**
     * Calculate MTTD (Mean Time To Diagnose)
     * Waktu dari Diagnosis Started hingga Repair Started
     */
    public function calculateMTTD()
    {
        if ($this->diagnosis_started_at && $this->repair_started_at) {
            $diagnosisStarted = Carbon::parse($this->diagnosis_started_at);
            $repairStarted = Carbon::parse($this->repair_started_at);
            
            // Pastikan repair started setelah diagnosis started
            if ($repairStarted->gt($diagnosisStarted)) {
                return abs($diagnosisStarted->diffInSeconds($repairStarted));
            }
        }
        return null;
    }

    /**
     * Calculate MTTR (Mean Time To Repair)
     * Waktu dari Repair Started hingga Repair Completed
     */
    public function calculateMTTR()
    {
        if ($this->repair_started_at && $this->repair_completed_at) {
            $repairStarted = Carbon::parse($this->repair_started_at);
            $repairCompleted = Carbon::parse($this->repair_completed_at);
            
            // Pastikan repair completed setelah repair started
            if ($repairCompleted->gt($repairStarted)) {
                return abs($repairStarted->diffInSeconds($repairCompleted));
            }
        }
        return null;
    }

    /**
     * Calculate Downtime
     * Downtime dihitung dari Problem Received hingga Repair Completed
     */
    public function calculateDowntime()
    {
        // Ambil problem_received_at dari ticketing atau dari problem jika belum ada
        $problemReceivedAt = $this->problem_received_at;
        if (!$problemReceivedAt && $this->problem && $this->problem->received_at) {
            $problemReceivedAt = $this->problem->received_at;
        }
        
        if ($problemReceivedAt && $this->repair_completed_at) {
            $received = Carbon::parse($problemReceivedAt);
            $repairCompleted = Carbon::parse($this->repair_completed_at);
            
            // Pastikan repair completed setelah problem received
            if ($repairCompleted->gt($received)) {
                return abs($received->diffInSeconds($repairCompleted));
            }
        }
        return null;
    }

    /**
     * Update calculated times
     * Update semua perhitungan waktu berdasarkan timestamp yang ada
     */
    public function updateCalculatedTimes()
    {
        // Refresh model untuk memastikan data terbaru dari database
        $this->refresh();
        
        // Hitung ulang semua durasi
        $mttdSeconds = $this->calculateMTTD();
        $mttrSeconds = $this->calculateMTTR();
        $downtimeSeconds = $this->calculateDowntime();
        
        // Log untuk debugging
        \Log::debug('Updating calculated times', [
            'ticketing_id' => $this->id,
            'problem_received_at' => $this->problem_received_at,
            'diagnosis_started_at' => $this->diagnosis_started_at,
            'repair_started_at' => $this->repair_started_at,
            'repair_completed_at' => $this->repair_completed_at,
            'calculated_mttd' => $mttdSeconds,
            'calculated_mttr' => $mttrSeconds,
            'calculated_downtime' => $downtimeSeconds
        ]);
        
        // Update nilai
        $this->mttd_seconds = $mttdSeconds;
        $this->mttr_seconds = $mttrSeconds;
        $this->downtime_seconds = $downtimeSeconds;
        
        // MTBF calculation would need historical data, so we'll leave it for now
        $this->mtbf_seconds = null;
        
        // Save tanpa trigger event untuk menghindari infinite loop
        // Gunakan withoutEvents untuk Laravel 5.7+ atau saveQuietly untuk Laravel 8+
        if (method_exists($this, 'saveQuietly')) {
            $this->saveQuietly();
        } else {
            static::withoutEvents(function () {
                $this->save();
            });
        }
        
        // Refresh lagi setelah save
        $this->refresh();
    }

    /**
     * Check if ticketing can be updated by user
     */
    public function canBeUpdatedBy($user)
    {
        // User maintenance, quality, dan engineering bisa update ticketing
        if (!in_array($user->role, ['maintenance', 'quality', 'engineering'])) {
            return false;
        }
        
        // Hanya creator atau user yang sama role yang bisa update
        return $this->created_by_user_id == $user->id || in_array($user->role, ['maintenance', 'quality', 'engineering']);
    }

    /**
     * Get status badge class
     */
    public function getStatusBadgeClassAttribute()
    {
        $classes = [
            'open' => 'badge-warning',
            'in_progress' => 'badge-info',
            'completed' => 'badge-success',
            'cancelled' => 'badge-danger'
        ];
        
        return $classes[$this->status] ?? 'badge-secondary';
    }

    /**
     * Get status label
     */
    public function getStatusLabelAttribute()
    {
        $labels = [
            'open' => 'Open',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled'
        ];
        
        return $labels[$this->status] ?? 'Unknown';
    }
}
