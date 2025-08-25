<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Class Subscription
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $chat_id
 * @property int $address_count
 * @property int $price_per_address
 * @property int $total_amount
 * @property string $status
 * @property string $starts_on
 * @property string $ends_on
 * @property int|null $payment_id
 */
class Subscription extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'chat_id',
        'address_count',
        'price_per_address',
        'total_amount',
        'starts_on',
        'ends_on',
        'status',
        'payment_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function addresses(): BelongsToMany
    {
        return $this->belongsToMany(Address::class, 'address_subscription')
            ->withTimestamps();
    }
}


