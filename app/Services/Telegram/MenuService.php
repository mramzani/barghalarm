<?php

namespace App\Services\Telegram;

/**
 * Builds and sends menus and common keyboards.
 */
class MenuService
{
    public function __construct(public TelegramService $telegram) {}

    public function sendMainMenu(int|string $chatId): void
    {
        $keyboard = [
            [
                $this->telegram->buildKeyboardButton('🗂️ آدرس‌های من'),
                $this->telegram->buildKeyboardButton('📍️ افزودن آدرس جدید'),
            ],
            [
                $this->telegram->buildKeyboardButton('🔴 وضعیت قطعی‌ها'),
            ],
            [
                $this->telegram->buildKeyboardButton('💡 درباره ما'),
                $this->telegram->buildKeyboardButton('📨 پیشنهاد یا گزارش مشکل'),
                // $this->telegram->buildKeyboardButton('📜 قوانین و مقررات'),
            ],
        ];
        $replyKeyboard = $this->telegram->buildKeyBoard($keyboard, false, true, true);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '👋 رفیق! به منو اصلی اومدی   '."\n\n".'یکی از گزینه‌ها رو انتخاب کن:'."\n\n".'👇👇👇',
            'reply_markup' => $replyKeyboard,
        ]);
    }

    public function requestPhoneShare(int|string $chatId): void
    {
        $keyboard = [
            [
                $this->telegram->buildKeyboardButton('👈 فعال‌سازی ربات 📱', true, false),
            ],
        ];
        $replyKeyboard = $this->telegram->buildKeyBoard($keyboard, true, true, true);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'دکمه زیر رو بزن تا حساب کاربریت فعالسازی بشه'."\n\n".'👇👇👇👇👇',
            'reply_markup' => $replyKeyboard,
        ]);
    }

    public function hideReplyKeyboard(int|string $chatId): void
    {
        // Use non-selective removal so the main reply keyboard is fully hidden
        $remove = $this->telegram->buildKeyBoardHide(false);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => ' ',
            'reply_markup' => $remove,
        ]);
    }
}
