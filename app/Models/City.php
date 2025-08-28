<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;

class City extends Model
{
    protected $fillable = [
        'name_fa',
        'name_en',
        'code',
    ];

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /**
     * Accessor for the virtual "name" attribute.
     *
     * Returns the Persian name by default for display purposes.
     */
    protected function name(): Attribute
    {
        return Attribute::get(function ($value, array $attributes): ?string {
            return $attributes['name_fa'] ?? null;
        });
    }

    public function blackouts(): HasMany
    {
        return $this->hasMany(Blackout::class);
    }
}
