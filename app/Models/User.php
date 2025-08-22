<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Authenticatable
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'mobile',
        'password',
        'is_verified',
        'is_active',
        'is_admin',
        'chat_id'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
        'is_admin' => 'boolean',
        'password' => 'hashed',
    ];

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function addresses(): BelongsToMany
    {
        return $this->belongsToMany(Address::class, 'adress_user')
            ->withPivot(['name', 'is_active'])
            ->withTimestamps();
    }

    public function city(): BelongsTo
    {
        return $this->address->city;
    }

    public function area(): BelongsTo
    {
        return $this->city->area;
    }

    public function fullName(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    public function mobile(): string
    {
        return $this->mobile;
    }

    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function isVerified(): bool
    {
        return $this->is_verified;
    }
}
