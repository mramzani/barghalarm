<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Address extends Model
{
    protected $fillable = [
        'city_id',
        'address',
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

    public function area(): BelongsTo
    {
        return $this->city->area;
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'adress_user')
            ->withPivot(['name'])
            ->withTimestamps();
    }

    public function fullAddress(): string
    {
        return $this->address . ' ' . $this->area->name . ' ' . $this->city->name;
    }

}
