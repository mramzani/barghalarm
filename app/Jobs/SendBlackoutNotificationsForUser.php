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
    public function __construct(public int $userId, public string $date) {}

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

        $sections = [];

        foreach ($user->addresses as $address) {
            $blackouts = Blackout::query()
                ->where('address_id', $address->id)
                ->whereDate('outage_date', $this->date)
                ->orderBy('outage_start_time')
                ->get(['outage_start_time', 'outage_end_time', 'outage_date']);

            $cityName = $address->city ? (string) $address->city->name() : '';
            $locationLine = '📍 '.trim(($cityName !== '' ? $cityName.' | ' : '').(string) $address->address, ' |');

            $addressSections = [];
            if ($blackouts->isEmpty()) {
                $addressSections[] = '<blockquote>'.e('✅ امروز برای این آدرس قطعی ثبت نشده است.').'</blockquote>';
            } else {
                foreach ($blackouts as $b) {
                    $start = $b->outage_start_time ? Carbon::parse($b->outage_start_time)->format('H:i') : '—';
                    $end = $b->outage_end_time ? Carbon::parse($b->outage_end_time)->format('H:i') : '—';
                    $addressSections[] = '<blockquote>'.e('⏰ '.$dateFa.' ساعت '.$start.' الی '.$end).'</blockquote>';
                }
            }

            $section = e($locationLine)."\n\n".implode("\n\n", $addressSections);

            if (! empty($sections)) {
                $sections[] = '🔹🔻🔻🔻🔻🔹';
            }

            $sections[] = $section;
        }

        $header = '📅 برنامه قطعی امروز ('.$dateFa.'):';
        $final = $header."\n\n".implode("\n\n", $sections);

        $telegram->sendMessage([
            'chat_id' => (int) $user->chat_id,
            'text' => $final,
            'parse_mode' => 'HTML',
        ]);
    }
}
