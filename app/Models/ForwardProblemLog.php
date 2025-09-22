<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class ForwardProblemLog extends Model
{
    protected $table = 'forward_problem_logs';
    
    protected $fillable = [
        'problem_id',
        'event_type',
        'user_id',
        'user_role',
        'target_role',
        'message',
        'event_timestamp',
        'metadata'
    ];
    
    protected $casts = [
        'event_timestamp' => 'datetime',
        'metadata' => 'array'
    ];
    
    // Event types constants
    const EVENT_FORWARD = 'forward';
    const EVENT_RECEIVE = 'receive';
    const EVENT_FEEDBACK_RESOLVED = 'feedback_resolved';
    const EVENT_FINAL_RESOLVED = 'final_resolved';
    
    /**
     * Relationship dengan Log (problem utama)
     */
    public function problem(): BelongsTo
    {
        return $this->belongsTo(Log::class, 'problem_id');
    }
    
    /**
     * Relationship dengan User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    /**
     * Scope untuk event tertentu
     */
    public function scopeEventType($query, $eventType)
    {
        return $query->where('event_type', $eventType);
    }
    
    /**
     * Scope untuk problem tertentu
     */
    public function scopeForProblem($query, $problemId)
    {
        return $query->where('problem_id', $problemId);
    }
    
    /**
     * Scope untuk user tertentu
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
    
    /**
     * Get formatted event timestamp
     */
    public function getFormattedEventTimestampAttribute()
    {
        return Carbon::parse($this->event_timestamp)->format('d/m/Y H:i:s');
    }
    
    /**
     * Get event description
     */
    public function getEventDescriptionAttribute()
    {
        $descriptions = [
            self::EVENT_FORWARD => 'Problem diteruskan',
            self::EVENT_RECEIVE => 'Problem diterima',
            self::EVENT_FEEDBACK_RESOLVED => 'Feedback problem selesai',
            self::EVENT_FINAL_RESOLVED => 'Problem diselesaikan secara final'
        ];
        
        return $descriptions[$this->event_type] ?? 'Event tidak dikenal';
    }
    
    /**
     * Static method untuk log event
     */
    public static function logEvent($problemId, $eventType, $userId, $userRole, $targetRole = null, $message = null, $metadata = null)
    {
        return self::create([
            'problem_id' => $problemId,
            'event_type' => $eventType,
            'user_id' => $userId,
            'user_role' => $userRole,
            'target_role' => $targetRole,
            'message' => $message,
            'event_timestamp' => now(),
            'metadata' => $metadata
        ]);
    }
}
