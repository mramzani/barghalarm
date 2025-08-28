<?php

namespace App\Console\Commands;

use App\Models\Blackout;
use App\Models\User;
use App\Services\Telegram\TelegramService;
use Hekmatinasser\Verta\Verta;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Class RemindUpcomingBlackoutsCommand
 *
 * Notifies users about upcoming blackouts approximately N minutes before the
 * outage start time for their active saved addresses.
 */
class RemindUpcomingBlackoutsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'blackouts:remind {--minutes=20} {--window=2} {--date=} {--dry-run}';

    /**
     * The console command description.
     */
    protected $description = 'Notify users ~20 minutes before blackout start for their active addresses';

    /**
     * Execute the console command.
     */
    public function handle(TelegramService $telegram): int
    {
        $minutes = (int) ($this->option('minutes') ?: 20);
        $window = (int) ($this->option('window') ?: 2); // tolerance window in minutes
        $date = (string) ($this->option('date') ?: Carbon::today()->toDateString());

        $now = Carbon::now();
        $targetFrom = (clone $now)->addMinutes($minutes)->subMinutes($window)->startOfMinute();
        $targetTo = (clone $now)->addMinutes($minutes)->addMinutes($window)->endOfMinute();

        $blackouts = Blackout::query()
            ->whereDate('outage_date', $date)
            ->whereTime('outage_start_time', '>=', $targetFrom->format('H:i:s'))
            ->whereTime('outage_start_time', '<=', $targetTo->format('H:i:s'))
            ->with(['address.city'])
            ->orderBy('outage_start_time')
            ->get();

        $notified = 0;

        // Aggregate sections per user to send a single message containing all relevant addresses
        $userMessages = [];

        foreach ($blackouts as $blackout) {
            $address = $blackout->address;
            if ($address === null) {
                continue;
            }

            $users = User::query()
                ->where('is_active', true)
                ->whereNotNull('chat_id')
                ->whereHas('addresses', function ($q) use ($address) {
                    $q->where('addresses.id', $address->id)
                        ->where('adress_user.is_active', 1);
                })
                ->get(['id', 'chat_id']);

            if ($users->isEmpty()) {
                continue;
            }

            $start = $blackout->outage_start_time ? Carbon::parse($blackout->outage_start_time)->format('H:i') : '—';
            $end = $blackout->outage_end_time ? Carbon::parse($blackout->outage_end_time)->format('H:i') : '—';
            $dateFa = (new Verta($date))->format('l j F');

            $cityName = (string) ($address->city?->name ?? '');
            $label = (string) ($address->address ?? '');
            $locationLine = '📍 ' . trim(($cityName !== '' ? $cityName . ' | ' : '') . $label, ' |');

            $reminderLine = '⏰ یادآوری: حدود ' . $minutes . ' دقیقه دیگر (' . $start . ') در تاریخ ' . $dateFa . ' برق شما قطع می‌شود.';
            $windowLine = '⏰ ' . $dateFa . ' ساعت ' . $start . ' الی ' . $end;

            // Build HTML section with blockquotes for the two reminder lines
            $section = '<blockquote>' . e($reminderLine) . '</blockquote>' . "\n\n"
                . e($locationLine) . "\n\n"
                . '<blockquote>' . e($windowLine) . '</blockquote>';

            foreach ($users as $user) {
                $dedupeKey = 'pre_outage_notified:' . $blackout->id . ':' . $user->id . ':' . Carbon::parse($blackout->outage_start_time)->timestamp;

                if (Cache::add($dedupeKey, true, now()->addHours(24))) {
                    // Initialize structure for this user
                    if (!isset($userMessages[$user->id])) {
                        $userMessages[$user->id] = [
                            'chat_id' => (int) $user->chat_id,
                            'sections' => [],
                        ];
                    }

                    // Add separator if there are previous sections
                    if (!empty($userMessages[$user->id]['sections'])) {
                        $userMessages[$user->id]['sections'][] = '🔹🔻🔻🔻🔻🔹';
                    }

                    // Personalize the address label per user (alias if exists, else fallback)
                    $alias = optional($user->addresses()->where('addresses.id', $address->id)->first()?->pivot)->name;
                    $personalized = $alias !== null && $alias !== ''
                        ? str_replace(e($label), e($alias), $section)
                        : $section;
                    $userMessages[$user->id]['sections'][] = $personalized;
                    $notified++;
                }
            }
        }

        // Send aggregated messages per user
        foreach ($userMessages as $userId => $payload) {
            $body = '🚨 هشدار قطعی برق' . "\n\n" . implode("\n\n", $payload['sections']);

            if ($this->option('dry-run')) {
                Log::info('DRY RUN pre-outage notify (aggregated)', [
                    'user_id' => $userId,
                    'chat_id' => $payload['chat_id'],
                    'message' => $body,
                ]);
            } else {
                $telegram->sendMessage([
                    'chat_id' => $payload['chat_id'],
                    'text' => $body,
                    'parse_mode' => 'HTML',
                ]);
            }
        }

        Log::info('Pre-outage reminders sent', [
            'count' => $notified,
            'minutes' => $minutes,
            'date' => $date,
            'window' => $window,
        ]);

        return self::SUCCESS;
    }
}
