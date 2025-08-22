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

        foreach ($blackouts as $blackout) {
            $address = $blackout->address;
            if ($address === null) {
                continue;
            }

            $users = User::query()
                ->where('is_verified', true)
                ->where('is_active', true)
                ->whereNotNull('chat_id')
                ->whereHas('addresses', function ($q) use ($address) {
                    $q->where('addresses.id', $address->id)
                        ->wherePivot('is_active', true);
                })
                ->get(['id', 'chat_id']);

            if ($users->isEmpty()) {
                continue;
            }

            $start = $blackout->outage_start_time ? Carbon::parse($blackout->outage_start_time)->format('H:i') : '—';
            $end = $blackout->outage_end_time ? Carbon::parse($blackout->outage_end_time)->format('H:i') : '—';
            $dateFa = (new Verta($date))->format('l j F');

            $cityName = optional($address->city)->name() ?? '';
            $header = '📍 ' . trim($cityName . ' - ' . $address->address, ' -');
            $text = $header . "\n"
                . '⏰ یادآوری: حدود ' . $minutes . ' دقیقه دیگر (' . $start . ') در تاریخ ' . $dateFa . ' برق شما قطع می‌شود.' . "\n"
                . 'بازه زمانی قطع: ' . $start . ' الی ' . $end;

            foreach ($users as $user) {
                $dedupeKey = 'pre_outage_notified:' . $blackout->id . ':' . $user->id . ':' . Carbon::parse($blackout->outage_start_time)->timestamp;

                if (Cache::add($dedupeKey, true, now()->addHours(24))) {
                    if ($this->option('dry-run')) {
                        Log::info('DRY RUN pre-outage notify', [
                            'user_id' => $user->id,
                            'chat_id' => $user->chat_id,
                            'blackout_id' => $blackout->id,
                        ]);
                    } else {
                        $telegram->sendMessage([
                            'chat_id' => (int) $user->chat_id,
                            'text' => $text,
                        ]);
                    }

                    $notified++;
                }
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
