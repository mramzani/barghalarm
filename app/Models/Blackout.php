<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Blackout extends Model
{

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'area_id',
        'city_id',
        'address_id',
        'outage_date',
        'outage_start_time',
        'outage_end_time',
        'description',
        'status',
        'outage_number',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'outage_date' => 'date',
        'outage_start_time' => 'datetime:H:i',
        'outage_end_time' => 'datetime:H:i',
        'status' => 'string',
    ];

    /**
     * Relation with Area
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }
    
    /**
     * Relation with City
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
    
    /**
     * Relation with Address
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    /**
     * Relation with User
     */
    public function user(): HasMany
    {
        return $this->address->users;
    }
    
    /**
     * Get the outage number
     */
    public function outageNumber(): int
    {
        return $this->outage_number;
    }
}
