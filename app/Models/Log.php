<?php
// File: app/Models/Log.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'line_number',
        'status',
        'resolved_at', 
        'duration_in_seconds',
        // Forward problem columns
        'is_forwarded',
        'forwarded_to_role',
        'forwarded_by_user_id',
        'forwarded_at',
        'forward_message',
        'is_received',
        'received_by_user_id',
        'received_at',
        'has_feedback_resolved',
        'feedback_resolved_by_user_id',
        'feedback_resolved_at',
        'feedback_message'
    ];
    
    // Cast tipe data dengan timezone Asia/Jakarta
    protected $casts = [
        'timestamp' => 'datetime:Y-m-d H:i:s',
        'resolved_at' => 'datetime:Y-m-d H:i:s',
        'forwarded_at' => 'datetime:Y-m-d H:i:s',
        'received_at' => 'datetime:Y-m-d H:i:s',
        'feedback_resolved_at' => 'datetime:Y-m-d H:i:s',
        'is_forwarded' => 'integer',
        'is_received' => 'integer',
        'has_feedback_resolved' => 'integer',
        'line_number' => 'integer',
        'duration_in_seconds' => 'integer',
        'forwarded_by_user_id' => 'integer',
        'received_by_user_id' => 'integer',
        'feedback_resolved_by_user_id' => 'integer'
    ];
    
    // Set timezone untuk semua kolom datetime
    protected $dates = [
        'timestamp',
        'resolved_at',
        'forwarded_at',
        'received_at',
        'feedback_resolved_at'
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
    }
    
    /**
     * Set timezone untuk semua kolom datetime
     */
    protected function setTimezoneForDates()
    {
        $dateColumns = ['timestamp', 'resolved_at', 'forwarded_at', 'received_at', 'feedback_resolved_at'];
        
        foreach ($dateColumns as $column) {
            if ($this->isDirty($column) && $this->$column) {
                $this->$column = Carbon::parse($this->$column, config('app.timezone'));
            }
        }
    }

    /**
     * Get formatted timestamp
     */
    public function getFormattedTimestampAttribute()
    {
        return Carbon::parse($this->timestamp, config('app.timezone'))->format('d/m/Y H:i:s');
    }

    /**
     * Get duration since problem started
     */
    public function getDurationAttribute()
    {
        // Parse timestamp dengan timezone dari config
        return Carbon::parse($this->timestamp, config('app.timezone'))->diffForHumans();
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

    /**
     * Relationship dengan User yang melakukan forward
     */
    public function forwardedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_by_user_id');
    }

    /**
     * Relationship dengan User yang menerima problem
     */
    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    /**
     * Relationship dengan User yang memberikan feedback resolved
     */
    public function feedbackResolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'feedback_resolved_by_user_id');
    }

    /**
     * Relationship dengan ForwardProblemLog
     */
    public function forwardLogs(): HasMany
    {
        return $this->hasMany(ForwardProblemLog::class, 'problem_id');
    }

    /**
     * Scope untuk problem yang sudah di-forward
     */
    public function scopeForwarded($query)
    {
        return $query->where('is_forwarded', true);
    }

    /**
     * Scope untuk problem yang sudah diterima
     */
    public function scopeReceived($query)
    {
        return $query->where('is_received', true);
    }

    /**
     * Scope untuk problem yang sudah ada feedback resolved
     */
    public function scopeWithFeedbackResolved($query)
    {
        return $query->where('has_feedback_resolved', true);
    }

    /**
     * Scope untuk problem berdasarkan role visibility
     */
    public function scopeVisibleToRole($query, $userRole, $userLineNumber = null)
    {
        switch ($userRole) {
            case 'admin':
                // Admin bisa melihat semua problem
                return $query;
                
            case 'leader':
                // Leader hanya bisa melihat problem di line mereka
                if ($userLineNumber) {
                    return $query->where('line_number', $userLineNumber);
                }
                return $query;
                
            case 'maintenance':
            case 'quality':
            case 'warehouse':
                // User department hanya bisa melihat problem yang sudah di-forward ke mereka
                return $query->where('is_forwarded', true)
                    ->where('forwarded_to_role', $userRole);
                
            default:
                return $query->whereRaw('1 = 0'); // Tidak ada yang bisa dilihat
        }
    }

    /**
     * Get problem status untuk display
     */
    public function getProblemStatusAttribute()
    {
        if ($this->status === 'OFF') {
            return 'resolved';
        }
        
        if ($this->has_feedback_resolved) {
            return 'feedback_resolved';
        }
        
        if ($this->is_received) {
            return 'received';
        }
        
        if ($this->is_forwarded) {
            return 'forwarded';
        }
        
        return 'active';
    }

    /**
     * Check apakah problem bisa di-forward oleh user
     */
    public function canBeForwardedBy($user)
    {
        if ($this->status !== 'ON') {
            return false;
        }
        
        if ($this->is_forwarded) {
            return false;
        }
        
        if ($user->role !== 'leader') {
            return false;
        }
        
        if ($user->line_number != $this->line_number) {
            return false;
        }
        
        return true;
    }

    /**
     * Check apakah problem bisa diterima oleh user
     */
    public function canBeReceivedBy($user)
    {
        if ($this->status !== 'ON') {
            return false;
        }
        
        if (!$this->is_forwarded) {
            return false;
        }
        
        if ($this->is_received) {
            return false;
        }
        
        if ($user->role !== $this->forwarded_to_role) {
            return false;
        }
        
        return true;
    }

    /**
     * Check apakah problem bisa di-feedback resolved oleh user
     */
    public function canBeFeedbackResolvedBy($user)
    {
        if ($this->status !== 'ON') {
            return false;
        }
        
        if (!$this->is_received) {
            return false;
        }
        
        if ($this->has_feedback_resolved) {
            return false;
        }
        
        if ($user->role !== $this->forwarded_to_role) {
            return false;
        }
        
        return true;
    }

    /**
     * Check apakah problem bisa di-resolved final oleh user
     */
    public function canBeFinalResolvedBy($user)
    {
        if ($this->status !== 'ON') {
            return false;
        }
        
        if (!$this->has_feedback_resolved) {
            return false;
        }
        
        if ($user->role !== 'leader') {
            return false;
        }
        
        if ($user->line_number != $this->line_number) {
            return false;
        }
        
        return true;
    }

    public function canBeDirectResolvedBy($user)
    {
        if ($this->status !== 'ON') {
            return false;
        }
        
        if ($this->is_forwarded) {
            return false; // Jika sudah di-forward, tidak bisa direct resolve
        }
        
        if ($user->role !== 'leader') {
            return false;
        }
        
        if ($user->line_number != $this->line_number) {
            return false;
        }
        
        return true;
    }
}