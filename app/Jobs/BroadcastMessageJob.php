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

        // Gather all user IDs to broadcast
        $userIds = [];
        User::query()
            ->whereNotNull('chat_id')
            ->orderBy('id')
            ->chunk(1000, function ($users) use (&$userIds) {
                foreach ($users as $user) {
                    $userIds[] = (int) $user->id;
                }
            });

        $total = count($userIds);
        $batchesTotal = (int) ceil($total / 50);

        $progress['total'] = $total;
        $progress['processing'] = 0;
        $progress['processed'] = 0;
        $progress['success'] = 0;
        $progress['failed'] = 0;
        $progress['remaining'] = $total;
        $progress['batches_total'] = $batchesTotal;
        $progress['batches_completed'] = 0;
        $progress['finalized'] = false;
        Cache::put($cacheKey, $progress, now()->addHour());
        $this->updateProgressMessage($telegram, $adminChatId, $progressMessageId, $progress);

        // Dispatch a job per 50 users
        for ($i = 0; $i < $total; $i += 50) {
            $batch = array_slice($userIds, $i, 50);
            \App\Jobs\BroadcastBatchJob::dispatch($this->broadcastId, $batch)->onQueue('default');
        }
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
        $batchesTotal = (int) ($stats['batches_total'] ?? 0);
        $batchesCompleted = (int) ($stats['batches_completed'] ?? 0);

        $lines = [];
        $lines[] = '📣 گزارش ارسال همگانی';
        $lines[] = '';
        $lines[] = '👥 کل ارسال: ' . number_format($total) . ' کاربر';
        $lines[] = '⏳ در حال ارسال: ' . number_format($processing) . ' کاربر';
        $lines[] = '📤 ارسال شده: ' . number_format($processed);
        $lines[] = '✅ موفق: ' . number_format($success);
        $lines[] = '❌ ناموفق: ' . number_format($failed);
        $lines[] = '🧮 باقی‌مانده: ' . number_format($remaining);
        if ($batchesTotal > 0) {
            $lines[] = '🧰 دسته‌ها: ' . number_format($batchesCompleted) . ' / ' . number_format($batchesTotal);
        }

        if ((bool) ($stats['cancelled'] ?? false)) {
            $lines[] = '';
            $lines[] = '⛔️ ارسال لغو شد.';
        }

        return implode("\n", $lines);
    }
}


