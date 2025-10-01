<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class UserSession extends Model
{
    protected $table = 'user_sessions';
    
    protected $fillable = [
        'user_id',
        'token',
        'ip_address',
        'user_agent',
        'expires_at'
    ];
    
    protected $casts = [
        'expires_at' => 'datetime',
        'user_id' => 'integer'
    ];
    
    /**
     * Relationship dengan User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    /**
     * Check apakah session masih valid
     */
    public function isValid(): bool
    {
        return $this->expires_at && $this->expires_at->isFuture();
    }
    
    /**
     * Scope untuk session yang masih valid
     */
    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', Carbon::now());
    }
    
    /**
     * Scope untuk session yang expired
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', Carbon::now());
    }
}
