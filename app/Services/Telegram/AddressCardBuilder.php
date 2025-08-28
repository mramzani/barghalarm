<?php
declare(strict_types=1);

namespace App\Services\Telegram;

/**
 * Builds address card text and inline keyboard.
 */
class AddressCardBuilder
{
    public function __construct(
        public TelegramService $telegram,
        public UserAddressService $userAddress,
    ) {
    }

    /**
     * @return array{0:?string,1:?string,2:?bool}
     */
    public function buildForUser(int|string $chatId, int $addressId): array
    {
        $user = $this->userAddress->findUserByChatId($chatId);
        if (!$user) {
            return [null, null, null];
        }

        $address = $user->addresses()->with('city')->where('addresses.id', $addressId)->first();
        if (!$address) {
            return [null, null, null];
        }

        $alias = $address->pivot->name ?? null;
        $cityName = $address->city ? '📍 ' . $address->city->name() : '';
        $pivotAlias = is_string($address->pivot->name ?? null) ? trim((string) $address->pivot->name) : '';
        $titleLine = $alias ? '📌 نام محل: ' . $alias . "\n" : '';
        $active = (bool) ($address->pivot->is_active ?? true);
        $status = $active ? '<blockquote>🔔 اعلان: روشن</blockquote>' : '<blockquote>🔕 اعلان: خاموش</blockquote>';
        $label = $pivotAlias !== '' ? $pivotAlias : (string) ($address->address ?? '');
        $text = $titleLine . trim(($cityName !== '' ? $cityName . ' | ' : '') . $label, ' |') . "\n\n" . $status;

        $buttons = [
            [
                $this->telegram->buildInlineKeyboardButton('حذف 🗑️', '', 'DEL_' . $address->id),
                $this->telegram->buildInlineKeyboardButton('برچسب ✏️', '', 'RENAME_' . $address->id),
            ],
            [
                $this->telegram->buildInlineKeyboardButton($active ? 'خاموش کردن اعلان 🔕' : 'روشن کردن اعلان 🔔', '', 'TOGGLE_' . $address->id),
                $this->telegram->buildInlineKeyboardButton('اشتراک‌گذاری 🔗', '', 'SHARE_' . $address->id),
            ],
        ];

        return [$text, $this->telegram->buildInlineKeyBoard($buttons), $active];
    }
}


