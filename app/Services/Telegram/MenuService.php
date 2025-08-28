<?php

namespace App\Services\Telegram;

use App\Models\User;

/**
 * Builds and sends menus and common keyboards.
 */
class MenuService
{
    public function __construct(public TelegramService $telegram) {}

    public function sendMainMenu(int|string $chatId): void
    {
        $keyboard = $this->buildMainMenuKeyboard($chatId);
        $replyKeyboard = $this->telegram->buildKeyBoard($keyboard, false, true, true);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '👋 رفیق! به منو اصلی اومدی   '."\n\n".'یکی از گزینه‌ها رو انتخاب کن:'."\n\n".'👇👇👇',
            'reply_markup' => $replyKeyboard,
        ]);
    }

    public function buildMainMenuKeyboard(int|string|null $chatId = null): array
    {
        $keyboard = [
            [
                $this->telegram->buildKeyboardButton('💬 دریافت هشدار با SMS'),
            ],
            [
                $this->telegram->buildKeyboardButton('🗂️ آدرس‌های من'),
                $this->telegram->buildKeyboardButton('📍️ افزودن آدرس جدید'),
            ],
            [
                $this->telegram->buildKeyboardButton('📆 قطعی‌های فردا'),
                $this->telegram->buildKeyboardButton('🔴 قطعی‌های امروز'),
            ],
            [
                $this->telegram->buildKeyboardButton('💡 درباره ما'),
                $this->telegram->buildKeyboardButton('📨 پیشنهاد یا گزارش مشکل'),
                // $this->telegram->buildKeyboardButton('📜 قوانین و مقررات'),
            ],
        ];

        if ($chatId !== null && $this->isAdmin($chatId)) {
            $keyboard[] = [
                $this->telegram->buildKeyboardButton('👤 مدیریت ربات'),
            ];
        }

        return $keyboard;
    }
    

    public function sendMainMenuWithMessage(int|string $chatId, string $text): void
    {
        $keyboard = $this->buildMainMenuKeyboard($chatId);

        $replyKeyboard = $this->telegram->buildKeyBoard($keyboard, false, true, true);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $replyKeyboard,
        ]);

    }

    public function buildAdminMenuKeyboard(): array
    {
        return [
            [
                $this->telegram->buildKeyboardButton('🙍‍♂️ آمار کاربران'),  
                $this->telegram->buildKeyboardButton('▶️ پیام همگانی'),
            ],
            [
                $this->telegram->buildKeyboardButton('↩️ بازگشت به منو اصلی'),
            ],
        ];
    }

    public function sendAdminMenu(int|string $chatId): void
    {
        $keyboard = $this->buildAdminMenuKeyboard();
        $replyKeyboard = $this->telegram->buildKeyBoard($keyboard, false, true, true);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'به منو مدیریت ربات خوش اومدی',
            'reply_markup' => $replyKeyboard,
        ]);
    }

    public function sendAdminMenuWithoutText(int|string $chatId): void
    {
        $keyboard = $this->buildAdminMenuKeyboard();
        $replyKeyboard = $this->telegram->buildKeyBoard($keyboard, false, true, true);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => ' ',
            'reply_markup' => $replyKeyboard,
        ]);
    }

    public function sendMainMenuWithoutIntro(int|string $chatId): void
    {
        $keyboard = $this->buildMainMenuKeyboard($chatId);
        $replyKeyboard = $this->telegram->buildKeyBoard($keyboard, false, true, true);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => ' ',
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


    public function isAdmin(int|string $chatId): bool
    {
        $chatIdString = (string) $chatId;
        $user = User::where('chat_id', $chatIdString)->first();
        $adminChatId = (string) config('services.telegram.admin_chat_id', '');

        if ($adminChatId === '') {
            return false;
        }

        if ($chatIdString !== $adminChatId) {
            return false;
        }

        return $user !== null && $user->isAdmin();
    }
}
