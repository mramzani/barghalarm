<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Telegram\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class BroadcastMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $broadcastId) {}

    public function handle(TelegramService $telegram): void
    {
        $cacheKey = 'broadcast:' . $this->broadcastId;
        $progress = (array) Cache::get($cacheKey, []);
        if (empty($progress)) {
            return;
        }

        $adminChatId = (string) ($progress['admin_chat_id'] ?? '');
        $progressMessageId = (int) ($progress['progress_message_id'] ?? 0);
        $text = (string) ($progress['text'] ?? '');
        if ($adminChatId === '' || $progressMessageId <= 0 || $text === '') {
            return;
        }

        $query = User::query()->whereNotNull('chat_id');

        $total = (int) $query->count();
        $progress['total'] = $total;
        $progress['processing'] = 0;
        $progress['processed'] = (int) ($progress['processed'] ?? 0);
        $progress['success'] = (int) ($progress['success'] ?? 0);
        $progress['failed'] = (int) ($progress['failed'] ?? 0);
        $progress['remaining'] = max(0, $total - $progress['processed']);
        Cache::put($cacheKey, $progress, now()->addHour());
        $this->updateProgressMessage($telegram, $adminChatId, $progressMessageId, $progress);

        $processedSinceLastUpdate = 0;

        $query->orderBy('id')->chunk(50, function ($users) use (&$progress, $cacheKey, $telegram, $adminChatId, $progressMessageId, &$processedSinceLastUpdate, $text) {
            if ((bool) ($progress['cancelled'] ?? false)) {
                return false; // stop further chunks
            }

            $batchTotal = count($users);
            $progress['processing'] = $batchTotal;
            Cache::put($cacheKey, $progress, now()->addHour());
            $this->updateProgressMessage($telegram, $adminChatId, $progressMessageId, $progress);

            foreach ($users as $user) {
                if ((bool) ($progress['cancelled'] ?? false)) {
                    break;
                }

                $chatId = (string) $user->chat_id;
                if ($chatId === '') {
                    $progress['processed']++;
                    $progress['failed']++;
                    $processedSinceLastUpdate++;
                    continue;
                }

                try {
                    $reply = $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => $text,
                    ]);
                    $ok = is_array($reply) ? (bool) ($reply['ok'] ?? false) : false;
                    if ($ok) {
                        $progress['success']++;
                    } else {
                        $progress['failed']++;
                    }
                } catch (\Throwable $e) {
                    $progress['failed']++;
                }

                $progress['processed']++;
                $progress['remaining'] = max(0, $progress['total'] - $progress['processed']);
                $processedSinceLastUpdate++;

                // Gentle rate limit to avoid Telegram flood (10 msgs/sec)
                usleep(100000);

                // Update admin every 5 messages to reduce API load
                if ($processedSinceLastUpdate >= 5) {
                    Cache::put($cacheKey, $progress, now()->addHour());
                    $this->updateProgressMessage($telegram, $adminChatId, $progressMessageId, $progress);
                    $processedSinceLastUpdate = 0;
                }
            }

            // End of batch update
            $progress['processing'] = 0;
            Cache::put($cacheKey, $progress, now()->addHour());
            $this->updateProgressMessage($telegram, $adminChatId, $progressMessageId, $progress);
        });

        // Finished: send completion message and return to admin menu
        $buttons = [];
        $telegram->editMessageText([
            'chat_id' => $adminChatId,
            'message_id' => $progressMessageId,
            'text' => $this->renderReport($progress) . "\n\n" . '✅ ارسال همگانی به پایان رسید.',
        ]);
        // Remove any lingering reply keyboards explicitly
        $telegram->sendMessage([
            'chat_id' => $adminChatId,
            'text' => ' ',
            'reply_markup' => $telegram->buildKeyBoardHide(false),
        ]);
    }

    private function updateProgressMessage(TelegramService $telegram, string $adminChatId, int $messageId, array $progress): void
    {
        $buttons = [
            [
                $telegram->buildInlineKeyboardButton('لغو ارسال ⛔️', '', 'CANCEL_BROADCAST'),
            ],
        ];

        $telegram->editMessageText([
            'chat_id' => $adminChatId,
            'message_id' => $messageId,
            'text' => $this->renderReport($progress),
            'reply_markup' => $telegram->buildInlineKeyBoard($buttons),
        ]);
    }

    private function renderReport(array $stats): string
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

        if ((bool) ($stats['cancelled'] ?? false)) {
            $lines[] = '';
            $lines[] = '⛔️ ارسال لغو شد.';
        }

        return implode("\n", $lines);
    }
}


