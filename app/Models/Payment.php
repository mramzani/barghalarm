<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class Payment
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $chat_id
 * @property int $address_count
 * @property int $amount
 * @property string $currency
 * @property string $gateway
 * @property string $status
 * @property string $token
 * @property \Carbon\CarbonImmutable|null $paid_at
 * @property array|null $meta
 */
class Payment extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'chat_id',
        'address_count',
        'amount',
        'gateway',
        'status',
        'ref_number',
        'authority',
        'paid_at',
        'meta',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'paid_at' => 'immutable_datetime',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}


