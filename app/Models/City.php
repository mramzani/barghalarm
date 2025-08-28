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

    public function name(): Attribute
    {
        return Attribute::get(function (): ?string {
            return $this->name_fa ?? $this->name_en;
        });
    }

    public function blackouts(): HasMany
    {
        return $this->hasMany(Blackout::class);
    }
}
