<?php
declare(strict_types=1);

namespace App\Services\Telegram;

/**
 * Handles feedback start/handle/cancel flow.
 */
class FeedbackService
{
    public function __construct(
        public TelegramService $telegram,
        public StateStore $state,
        public MenuService $menu,
        public UserAddressService $userAddress,
    ) {
    }

    public function start(int|string $chatId): void
    {
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "بخدا این ربات برای اداره برق نیست، اگه میخوای فهش بدی بیخیال شو 😁\n\nبرای ارسال پیشنهاد یا گزارش مشکل، لطفاً به آیدی زیر پیام بدهید:\n<a href='https://t.me/mohamadramzani1994'>@mohamadramzani1994</a>\n\nممنون از همراهی‌تون! 😊",
                'parse_mode' => 'HTML',
            ]);

        $this->menu->sendMainMenu($chatId);
    }

    public function start2(int|string $chatId): void
    {
        $this->state->set($chatId, ['step' => 'await_feedback']);
        $keyboard = [
            [
                $this->telegram->buildKeyboardButton('انصراف'),
            ],
        ];
        $replyKeyboard = $this->telegram->buildKeyBoard($keyboard, true, true, true);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "ممنون از همراهی‌تون! 😊\nلطفاً پیشنهاد یا گزارش مشکل خود را در یک پیام ارسال کنید.\n\nلطفاً درباره موارد زیر پیام ارسال نکنید:\n1. اگر ساعتی برای قطعی اعلام شده اما برق قطع نشده است.\n2. اگر ساعتی برای قطعی اعلام نشده اما برق قطع شده است.\nما مسئول این موارد نیستیم. 🙏🏻\n\nهمه پیام‌ها با دقت توسط مدیر بررسی می‌شوند. 🌟",
            'reply_markup' => $replyKeyboard,
        ]);
    }

    public function cancel(int|string $chatId): void
    {
        $this->state->clear($chatId);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '❌ ارسال پیشنهاد/گزارش لغو شد.',
        ]);
        $this->menu->hideReplyKeyboard($chatId);
        $this->menu->sendMainMenu($chatId);
    }

    public function handle(int|string $chatId, string $text): void
    {
        $user = $this->userAddress->findUserByChatId($chatId);
        $firstName = $user ? (string) ($user->first_name ?? '') : (string) ($this->telegram->FirstName() ?? '');
        $lastName = $user ? (string) ($user->last_name ?? '') : (string) ($this->telegram->LastName() ?? '');
        $username = (string) ($this->telegram->Username() ?? '');
        $mobile = $user ? (string) ($user->mobile ?? '') : '-';

        $name = trim(($firstName . ' ' . $lastName)) ?: '-';
        $usernameLine = $username !== '' ? '@' . $username : '-';

        $adminChatId = (string) config('services.telegram.admin_chat_id', '');
        if ($adminChatId !== '') {
            $adminMessage = "📬 پیام جدید از کاربر\n\n"
                . '👤 نام: ' . $name . "\n"
                . '🆔 ChatID: ' . $chatId . "\n"
                . '🏷️ Username: ' . $usernameLine . "\n"
                . '📱 موبایل: ' . $mobile . "\n\n"
                . "متن:\n" . $text;

            $this->telegram->sendMessage([
                'chat_id' => $adminChatId,
                'text' => $adminMessage,
            ]);
        }

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '✅ پیام شما برای مدیر ارسال شد. ممنون از همراهی‌تون!',
        ]);
        $this->state->clear($chatId);
        $this->menu->hideReplyKeyboard($chatId);
        $this->menu->sendMainMenu($chatId);
    }
}


