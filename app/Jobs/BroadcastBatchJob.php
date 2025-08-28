<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Telegram\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Sends broadcast message to a fixed set of users (batch of size <= 50).
 */
class BroadcastBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array<int,int> $userIds
     */
    public function __construct(
        public string $broadcastId,
        public array $userIds,
    ) {
    }

    public function handle(TelegramService $telegram): void
    {
        $cacheKey = 'broadcast:' . $this->broadcastId;
        $progress = (array) Cache::get($cacheKey, []);
        if (empty($progress)) {
            return;
        }

        if ((bool) ($progress['cancelled'] ?? false)) {
            return;
        }

        $adminChatId = (string) ($progress['admin_chat_id'] ?? '');
        $progressMessageId = (int) ($progress['progress_message_id'] ?? 0);
        $text = (string) ($progress['text'] ?? '');
        if ($adminChatId === '' || $progressMessageId <= 0 || $text === '') {
            return;
        }

        $batchProcessed = 0;
        $batchSuccess = 0;
        $batchFailed = 0;
        $sinceLastUpdate = 0;

        foreach ($this->userIds as $userId) {
            $progress = (array) Cache::get($cacheKey, []);
            if ((bool) ($progress['cancelled'] ?? false)) {
                break;
            }

            $user = User::query()->find($userId);
            if ($user === null) {
                $batchProcessed++;
                $batchFailed++;
                $sinceLastUpdate++;
                continue;
            }

            $chatId = (string) $user->chat_id;
            if ($chatId === '') {
                $batchProcessed++;
                $batchFailed++;
                $sinceLastUpdate++;
                continue;
            }

            try {
                $reply = $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);
                $ok = is_array($reply) ? (bool) ($reply['ok'] ?? false) : false;
                if ($ok) {
                    $batchSuccess++;
                } else {
                    $batchFailed++;
                }
            } catch (\Throwable) {
                $batchFailed++;
            }

            $batchProcessed++;
            $sinceLastUpdate++;

            // Gentle rate limit per worker
            usleep(100000);

            if ($sinceLastUpdate >= 5) {
                $this->updateSharedProgress($cacheKey, $telegram, $adminChatId, $progressMessageId, $batchProcessed, $batchSuccess, $batchFailed, false);
                $sinceLastUpdate = 0;
            }
        }

        // Final update for this batch
        $this->updateSharedProgress($cacheKey, $telegram, $adminChatId, $progressMessageId, $batchProcessed, $batchSuccess, $batchFailed, true);
    }

    private function updateSharedProgress(string $cacheKey, TelegramService $telegram, string $adminChatId, int $messageId, int $deltaProcessed, int $deltaSuccess, int $deltaFailed, bool $isBatchEnd): void
    {
        $lockKey = $cacheKey . ':lock';
        $lock = Cache::lock($lockKey, 5);

        try {
            $lock->block(5);

            $progress = (array) Cache::get($cacheKey, []);
            if (empty($progress)) {
                $lock->release();
                return;
            }

            $progress['processed'] = (int) ($progress['processed'] ?? 0) + $deltaProcessed;
            $progress['success'] = (int) ($progress['success'] ?? 0) + $deltaSuccess;
            $progress['failed'] = (int) ($progress['failed'] ?? 0) + $deltaFailed;
            $progress['remaining'] = max(0, (int) ($progress['total'] ?? 0) - (int) $progress['processed']);

            if ($isBatchEnd) {
                $progress['batches_completed'] = (int) ($progress['batches_completed'] ?? 0) + 1;
            }

            Cache::put($cacheKey, $progress, now()->addHour());

            // Update admin progress message
            $telegram->editMessageText([
                'chat_id' => $adminChatId,
                'message_id' => $messageId,
                'text' => $this->renderReport($progress),
                'reply_markup' => $telegram->buildInlineKeyBoard([
                    [
                        $telegram->buildInlineKeyboardButton('لغو ارسال ⛔️', '', 'CANCEL_BROADCAST'),
                    ],
                ]),
            ]);

            // Finalize once, when all batches are completed
            $allDone = ((int) ($progress['batches_completed'] ?? 0)) >= ((int) ($progress['batches_total'] ?? PHP_INT_MAX));
            $alreadyFinalized = (bool) ($progress['finalized'] ?? false);
            if ($allDone && !$alreadyFinalized) {
                $progress['finalized'] = true;
                Cache::put($cacheKey, $progress, now()->addHour());

                $telegram->editMessageText([
                    'chat_id' => $adminChatId,
                    'message_id' => $messageId,
                    'text' => $this->renderReport($progress) . "\n\n" . '✅ ارسال همگانی به پایان رسید.',
                ]);

                // Remove any lingering reply keyboards explicitly
                $telegram->sendMessage([
                    'chat_id' => $adminChatId,
                    'text' => ' ',
                    'reply_markup' => $telegram->buildKeyBoardHide(false),
                ]);
            }

            $lock->release();
        } catch (LockTimeoutException) {
            // If we failed to acquire the lock in time, skip this update; another batch will update soon.
        }
    }

    /**
     * @param array{total?:int,processing?:int,processed?:int,success?:int,failed?:int,remaining?:int,cancelled?:bool,batches_total?:int,batches_completed?:int} $stats
     */
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


