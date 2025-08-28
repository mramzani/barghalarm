<?php
declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;
use App\Models\User;

/**
 * Handles admin broadcast compose/confirm/edit/abort/cancel and stats menu wiring.
 */
class BroadcastService
{
    public function __construct(
        public TelegramService $telegram,
        public StateStore $state,
        public MenuService $menu,
    ) {
    }

    public function isAdmin(int|string $chatId): bool
    {
        $chatIdString = (string) $chatId;
        $user = User::query()->where('chat_id', $chatIdString)->first();
        $adminChatId = (string) config('services.telegram.admin_chat_id', '');

        if ($adminChatId === '') {
            return false;
        }

        if ($chatIdString !== $adminChatId) {
            return false;
        }

        return $user->isAdmin();
    }

    public function startCompose(int|string $chatId): void
    {
        $this->state->set($chatId, ['step' => 'await_broadcast_text']);
        $this->menu->hideReplyKeyboard($chatId);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '📝 لطفاً متن پیام همگانی را ارسال کنید.',
            'reply_markup' => $this->telegram->buildInlineKeyBoard([
                [
                    $this->telegram->buildInlineKeyboardButton('انصراف', '', 'BROADCAST_ABORT'),
                ],
            ]),
        ]);
    }

    public function handleText(int|string $chatId, string $text): void
    {
        if ($text === 'انصراف') {
            $this->state->clear($chatId);
            $this->menu->hideReplyKeyboard($chatId);
            $this->menu->sendAdminMenu($chatId);
            return;
        }

        $this->state->set($chatId, [
            'step' => 'await_broadcast_confirm',
            'broadcast_text' => $text,
        ]);

        $this->menu->hideReplyKeyboard($chatId);
        $confirmText = "متن پیام همگانی به شکل زیر می‌باشد:\n\n" . $text . "\n\n" . 'آیا مورد تایید است؟';
        $buttons = [
            [
                $this->telegram->buildInlineKeyboardButton('تایید و ارسال ✅', '', 'BROADCAST_CONFIRM'),
                $this->telegram->buildInlineKeyboardButton('ویرایش پیام ✏️', '', 'BROADCAST_EDIT'),
            ],
        ];
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $confirmText,
            'reply_markup' => $this->telegram->buildInlineKeyBoard($buttons),
        ]);
    }

    /**
     * @param array{total:int,processing:int,processed:int,success:int,failed:int,remaining:int} $stats
     */
    public function renderReport(array $stats): string
    {
        $total = (int) ($stats['total'] ?? 0);
        $processing = (int) ($stats['processing'] ?? 0);
        $processed = (int) ($stats['processed'] ?? 0);
        $success = (int) ($stats['success'] ?? 0);
        $failed = (int) ($stats['failed'] ?? 0);
        $remaining = (int) ($stats['remaining'] ?? max(0, $total - $processed));

        $lines = [];
        $lines[] = '📣 گزارش ارسال همگانی';
        $lines[] = '';
        $lines[] = '👥 کل ارسال: ' . number_format($total) . ' کاربر';
        $lines[] = '⏳ در حال ارسال: ' . number_format($processing) . ' کاربر';
        $lines[] = '📤 ارسال شده: ' . number_format($processed);
        $lines[] = '✅ موفق: ' . number_format($success);
        $lines[] = '❌ ناموفق: ' . number_format($failed);
        $lines[] = '🧮 باقی‌مانده: ' . number_format($remaining);

        return implode("\n", $lines);
    }

    public function confirmAndDispatch(int|string $chatId): void
    {
        $state = $this->state->get($chatId);
        $message = (string) ($state['broadcast_text'] ?? '');
        if ($message === '') {
            $this->menu->sendAdminMenu($chatId);
            return;
        }

        $initialReport = $this->renderReport([
            'total' => 0,
            'processing' => 0,
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'remaining' => 0,
        ]);
        $progressButtons = [
            [
                $this->telegram->buildInlineKeyboardButton('لغو ارسال ⛔️', '', 'CANCEL_BROADCAST'),
            ],
        ];
        $this->menu->hideReplyKeyboard($chatId);

        $sent = $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $initialReport,
            'reply_markup' => $this->telegram->buildInlineKeyBoard($progressButtons),
        ]);

        $messageId = is_array($sent) ? (int) ($sent['result']['message_id'] ?? 0) : 0;
        $broadcastId = 'b_' . $chatId . '_' . time();

        Cache::put('broadcast:active:' . $chatId, $broadcastId, now()->addHour());
        Cache::put('broadcast:' . $broadcastId, [
            'total' => 0,
            'processing' => 0,
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'remaining' => 0,
            'cancelled' => false,
            'admin_chat_id' => (string) $chatId,
            'progress_message_id' => $messageId,
            'text' => $message,
        ], now()->addHour());

        \App\Jobs\BroadcastMessageJob::dispatch($broadcastId)->onQueue('default');

        $this->state->clear($chatId);
    }

    public function edit(int|string $chatId): void
    {
        $this->state->set($chatId, ['step' => 'await_broadcast_text']);
        $this->menu->hideReplyKeyboard($chatId);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '📝 لطفاً متن جدید پیام همگانی را ارسال کنید.',
            'reply_markup' => $this->telegram->buildInlineKeyBoard([
                [
                    $this->telegram->buildInlineKeyboardButton('انصراف', '', 'BROADCAST_ABORT'),
                ],
            ]),
        ]);
    }

    public function abort(int|string $chatId): void
    {
        $this->state->clear($chatId);
        $this->menu->hideReplyKeyboard($chatId);
        $this->menu->sendAdminMenu($chatId);
    }

    public function cancelActive(int|string $chatId): void
    {
        $broadcastId = Cache::get('broadcast:active:' . $chatId);
        if ($broadcastId) {
            $progress = (array) Cache::get('broadcast:' . $broadcastId, []);
            $progress['cancelled'] = true;
            Cache::put('broadcast:' . $broadcastId, $progress, now()->addHour());
        }
    }
}


