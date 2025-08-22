<?php

namespace App\Services\Telegram;

/**
 * Builds and sends menus and common keyboards.
 */
class MenuService
{
    public function __construct(public TelegramService $telegram)
    {
    }

    public function sendMainMenu(int|string $chatId): void
    {
        $keyboard = [
            [
                $this->telegram->buildKeyboardButton('🗂️ مدیریت آدرس‌ها'),
                $this->telegram->buildKeyboardButton('📍️ افزودن آدرس جدید'),
            ],
            [
                $this->telegram->buildKeyboardButton('🔴 وضعیت قطعی‌ها'),
            ],
            [
                $this->telegram->buildKeyboardButton('💡 درباره ما'),
                $this->telegram->buildKeyboardButton('📜 قوانین و مقررات'),
            ],
            [
                $this->telegram->buildKeyboardButton('📨 پیشنهاد یا گزارش مشکل'),
            ],
        ];
        $replyKeyboard = $this->telegram->buildKeyBoard($keyboard, false, true, true);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '👋 رفیق! اینم منوی اصلی. یکی از گزینه‌ها رو انتخاب کن:',
            'reply_markup' => $replyKeyboard,
        ]);
    }

    public function requestPhoneShare(int|string $chatId): void
    {
        $keyboard = [
            [
                $this->telegram->buildKeyboardButton('اشتراک‌گذاری شماره موبایل 📱', true, false),
            ],
        ];
        $replyKeyboard = $this->telegram->buildKeyBoard($keyboard, true, true, true);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'برای فعال‌سازی، لطفاً شماره موبایل خود را با دکمه زیر ارسال کنید (فقط شماره ایران).',
            'reply_markup' => $replyKeyboard,
        ]);
    }

    public function hideReplyKeyboard(int|string $chatId): void
    {
        $remove = $this->telegram->buildKeyBoardHide(true);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => ' ',
            'reply_markup' => $remove,
        ]);
    }
}


