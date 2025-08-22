<?php

namespace App\Console\Commands;

use App\Models\Blackout;
use App\Models\User;
use App\Services\Telegram\TelegramService;
use Hekmatinasser\Verta\Verta;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CheckBlackoutsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'blackouts:check {--date=}';

    /**
     * The console command description.
     */
    protected $description = 'Notify users about today\'s blackout schedules for their saved addresses';

    public function handle(TelegramService $telegram): int
    {
        $date = (string) ($this->option('date') ?: Carbon::today()->toDateString());
        $dateFa = (new Verta($date))->format('l j F');

        $users = User::query()
            ->where('is_verified', true)
            ->where('is_active', true)
            ->whereNotNull('chat_id')
            ->with(['addresses' => function ($q) {
                $q->wherePivot('is_active', true);
            }, 'addresses.city'])
            ->get();

        $notified = 0;

        foreach ($users as $user) {
            foreach ($user->addresses as $address) {
                $blackouts = Blackout::query()
                    ->where('address_id', $address->id)
                    ->whereDate('outage_date', $date)
                    ->orderBy('outage_start_time')
                    ->get(['outage_start_time', 'outage_end_time']);

                if ($blackouts->isEmpty()) {
                    continue;
                }

                $lines = [];
                foreach ($blackouts as $index => $b) {
                    $start = $b->outage_start_time ? Carbon::parse($b->outage_start_time)->format('H:i') : '—';
                    $end = $b->outage_end_time ? Carbon::parse($b->outage_end_time)->format('H:i') : '—';
                    $num = $index + 1;
                    $lines[] = $num . '. ' . 'ساعت ' . $start . ' الی ' . $end;
                }

                $cityName = optional($address->city)->name() ?? '';
                $header = '📍 ' . trim($cityName . ' - ' . $address->address, ' -');
                $text = $header . "\n" . '⏰ طبق اطلاع شرکت برق، زمان قطع برق شما در تاریخ ' . $dateFa . ' :' . "\n" . implode("\n", $lines);

                $telegram->sendMessage([
                    'chat_id' => (int) $user->chat_id,
                    'text' => $text,
                ]);

                $notified++;
            }
        }

        //$this->info('Notifications sent for addresses: ' . $notified);
        Log::info('Notifications sent for addresses: ' . $notified);
        return self::SUCCESS;
    }
}


