<?php

namespace App\Services\Telegram;

/**
 * Class PhoneNumberNormalizer
 *
 * Normalizes and validates Iranian mobile numbers to E.164-like format +989XXXXXXXXX.
 */
class PhoneNumberNormalizer
{
    /**
     * Normalize a raw string to Iranian mobile format if valid.
     *
     * @param string $raw
     * @return string|null Returns normalized "+989XXXXXXXXX" or null if invalid
     */
    public function normalizeIranMobile(string $raw): ?string
    {
        $digits = preg_replace('/[^0-9+]/', '', $raw);
        if ($digits === null) {
            return null;
        }

        if (preg_match('/^\+?98(9\d{9})$/', $digits, $m)) {
            return '+98' . $m[1];
        }
        if (preg_match('/^0(9\d{9})$/', $digits, $m)) {
            return '+98' . $m[1];
        }

        return null;
    }
}


