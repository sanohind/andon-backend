<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Division extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    /**
     * Get the lines for this division.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(Line::class);
    }
}

