<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Area extends Model
{
    protected $fillable = [
        'city_id',
        'name',
        'code',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function blackouts(): HasMany
    {
        return $this->hasMany(Blackout::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }
}
