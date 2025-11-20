<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Line extends Model
{
    use HasFactory;

    protected $fillable = ['division_id', 'name'];

    /**
     * Get the division that owns this line.
     */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }
}

