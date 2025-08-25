<?php

namespace App\Services\Billing;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Handles pricing calculation, payment creation, and subscription provisioning.
 */
class SubscriptionBillingService
{
    public const PRICE_PER_ADDRESS = 30000; // Toman per month

    public function calculateMonthlyCost(int $addressCount): int
    {
        if ($addressCount < 0) {
            $addressCount = 0;
        }
        return $addressCount * self::PRICE_PER_ADDRESS;
    }

    /**
     * Compute uncovered address IDs for a user: addresses without any active subscription coverage.
     * If none, returns empty array.
     *
     * @return array<int>
     */
    public function getUncoveredAddressIds(User $user): array
    {
        $userAddressIds = $user->addresses()->pluck('addresses.id')->all();
        if (empty($userAddressIds)) {
            return [];
        }

        // Collect address ids covered by user's active subscriptions
        $covered = [];
        $activeSubs = $user->subscriptions()->where('status', 'active')->get();
        foreach ($activeSubs as $sub) {
            $ids = $sub->addresses()->pluck('addresses.id')->all();
            if (!empty($ids)) {
                $covered = array_merge($covered, $ids);
            }
        }
        $covered = array_unique($covered);

        return array_values(array_diff($userAddressIds, $covered));
    }

    public function createPendingPayment(User $user, int $addressCount, ?string $chatId = null, array $addressIds = []): Payment
    {
        $amountToman = $this->calculateMonthlyCost($addressCount);
        $payment = new Payment();
        $payment->user_id = $user->id;
        $payment->chat_id = $chatId;
        $payment->address_count = $addressCount;
        $payment->amount = $amountToman * 10; // store in IRR
        $payment->gateway = 'zarinpal';
        $payment->status = 'pending';
        $payment->meta = [
            'price_per_address_toman' => self::PRICE_PER_ADDRESS,
            'address_ids' => array_values($addressIds),
        ];
        $payment->save();

        return $payment;
    }

    /**
     * Find a recent pending payment for this user/context or create a new one.
     */
    public function getOrCreatePendingPayment(User $user, int $addressCount, ?string $chatId = null, array $addressIds = []): Payment
    {
        $existing = Payment::query()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->where('address_count', $addressCount)
            ->when($chatId !== null, fn ($q) => $q->where('chat_id', $chatId))
            ->where('created_at', '>=', Carbon::now()->subMinutes(30))
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        return $this->createPendingPayment($user, $addressCount, $chatId, $addressIds);
    }

    public function markPaymentPaid(Payment $payment): Payment
    {
        $payment->status = 'paid';
        $payment->paid_at = Carbon::now();
        $payment->save();
        return $payment;
    }

    public function provisionMonthlySubscription(User $user, Payment $payment): Subscription
    {
        $starts = Carbon::today();
        $ends = (clone $starts)->addMonth();

        $sub = new Subscription();
        $sub->user_id = $user->id;
        $sub->chat_id = $payment->chat_id;
        $sub->address_count = $payment->address_count;
        $sub->price_per_address = self::PRICE_PER_ADDRESS * 10; // store in IRR
        $sub->total_amount = $payment->amount;
        $sub->starts_on = $starts->toDateString();
        $sub->ends_on = $ends->toDateString();
        $sub->status = 'active';
        $sub->payment_id = $payment->id;
        $sub->save();

        // Attach the addresses that this payment was meant to cover
        $addressIds = (array) ($payment->meta['address_ids'] ?? []);
        if (!empty($addressIds)) {
            $sub->addresses()->syncWithoutDetaching($addressIds);
        }

        return $sub;
    }
}


