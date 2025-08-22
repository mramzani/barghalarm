<?php

namespace App\Services\Telegram;

use App\Models\Address;
use App\Models\User;

/**
 * Encapsulates user and address related operations.
 */
class UserAddressService
{
    public function findUserByChatId(int|string $chatId): ?User
    {
        return User::where('chat_id', $chatId)->first();
    }

    public function ensureUserExists(int|string $chatId, string $firstName = '', string $lastName = ''): void
    {
        if (!$chatId) {
            return;
        }
        $existing = User::where('chat_id', $chatId)->first();
        if ($existing) {
            return;
        }
        User::create([
            'chat_id' => (int) $chatId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'mobile' => 'tg:' . (int) $chatId,
            'is_verified' => false,
            'is_active' => false,
        ]);
    }

    public function isVerified(int|string $chatId): bool
    {
        $user = $this->findUserByChatId($chatId);

        return $user ? (bool) $user->is_verified : false;
    }

    public function attachUserAddress(int|string $chatId, int $addressId): void
    {
        $user = $this->findUserByChatId($chatId);
        if (!$user) {
            return;
        }
        if (!$user->addresses()->where('addresses.id', $addressId)->exists()) {
            $user->addresses()->attach($addressId);
        }
        if (!$user->is_active) {
            $user->is_active = true;
            $user->save();
        }
    }

    public function removeUserAddress(int|string $chatId, int $addressId): void
    {
        $user = $this->findUserByChatId($chatId);
        if ($user) {
            $user->addresses()->detach($addressId);
        }
    }

    public function setAddressAlias(int|string $chatId, int $addressId, string $alias): void
    {
        $user = $this->findUserByChatId($chatId);
        if ($user && $user->addresses()->where('addresses.id', $addressId)->exists()) {
            $user->addresses()->updateExistingPivot($addressId, ['name' => $alias]);
        }
    }

    public function toggleAddressNotify(int|string $chatId, int $addressId): void
    {
        $user = $this->findUserByChatId($chatId);
        if (!$user) {
            return;
        }
        if ($user->addresses()->where('addresses.id', $addressId)->exists()) {
            $pivot = $user->addresses()->where('addresses.id', $addressId)->first()->pivot;
            $new = !((bool) ($pivot->is_active ?? true));
            $user->addresses()->updateExistingPivot($addressId, ['is_active' => $new]);
        }
    }

    public function confirmAddressAddedMessageParts(?Address $address): array
    {
        if (!$address) {
            return ['✅ آدرس شما با موفقیت اضافه شد.', null];
        }
        $cityName = $address->city ? $address->city->name() : '';
        $msg = '✅ آدرس شما با موفقیت اضافه شد:' . "\n" . $cityName . "\n" . $address->address . "\n\n" . '🔔 از این پس در صورت وجود قطعی برق در این آدرس،  به شما اطلاع داده خواهد شد.';

        return [$msg, $cityName];
    }
}


