<?php

namespace App\Jobs;

use App\Models\Blackout;
use App\Models\User;
use App\Services\Telegram\TelegramService;
use Hekmatinasser\Verta\Verta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Job to send blackout notifications for a single user.
 */
class SendBlackoutNotificationsForUser implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $userId, public string $date)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(TelegramService $telegram): void
    {
        $user = User::query()
            ->whereKey($this->userId)
            ->where('is_active', true)
            ->whereNotNull('chat_id')
            ->with([
                'addresses' => function ($query): void {
                    $query->wherePivot('is_active', true);
                },
                'addresses.city',
            ])
            ->first();

        if ($user === null) {
            return;
        }

        $dateFa = (new Verta($this->date))->format('l j F');

        foreach ($user->addresses as $address) {
            $blackouts = Blackout::query()
                ->where('address_id', $address->id)
                ->whereDate('outage_date', $this->date)
                ->orderBy('outage_start_time')
                ->get(['outage_start_time', 'outage_end_time']);

            if ($blackouts->isEmpty()) {
                continue;
            }

            $lines = [];
            foreach ($blackouts as $index => $blackout) {
                $start = $blackout->outage_start_time !== null
                    ? Carbon::parse($blackout->outage_start_time)->format('H:i')
                    : '—';
                $end = $blackout->outage_end_time !== null
                    ? Carbon::parse($blackout->outage_end_time)->format('H:i')
                    : '—';
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
        }
    }
}


