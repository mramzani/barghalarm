<?php

namespace App\Console\Commands;

use App\Jobs\MorinogNotificationJob;
use App\Models\Blackout;
use App\Models\Subscription;
use App\Models\User;
use Hekmatinasser\Verta\Verta;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SmsBlackoutsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'blackouts:sms {--date=} {--dry-run}';

    /**
     * The console command description.
     */
    protected $description = 'Send SMS notifications to users with active subscriptions about today\'s blackouts';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $date = (string) ($this->option('date') ?: Carbon::today()->toDateString());

        $blackouts = Blackout::query()
            ->whereDate('outage_date', $date)
            ->with(['address.city'])
            ->orderBy('outage_start_time')
            ->get();

        $dispatched = 0;

        foreach ($blackouts as $blackout) {
            $address = $blackout->address;
            if ($address === null) {
                continue;
            }

            $start = $blackout->outage_start_time ? Carbon::parse($blackout->outage_start_time)->format('H:i') : '';
            $cityName = optional($address->city)->name() ?? '';
            $locationLine = trim(($cityName !== '' ? $cityName . ' | ' : '') . (string) $address->address, ' |');

            // Users with active subscription covering this address and valid mobile
            $users = User::query()
                ->where('is_active', true)
                ->whereNotNull('mobile')
                ->whereHas('subscriptions', function ($q) use ($address, $date): void {
                    $q->where('status', 'active')
                        ->whereDate('ends_on', '>=', $date)
                        ->whereHas('addresses', function ($qa) use ($address): void {
                            $qa->where('addresses.id', $address->id);
                        });
                })
                ->get(['id']);

            foreach ($users as $user) {
                $smsDedupe = 'daily_sms_notified:' . $date . ':' . $blackout->id . ':' . $user->id;
                if (Cache::add($smsDedupe, true, now()->addHours(24))) {
                    $args = [
                        $locationLine,
                        (string) $start,
                    ];
                    if ($this->option('dry-run')) {
                        Log::info('DRY RUN SMS', [
                            'user_id' => $user->id,
                            'args' => $args,
                            'date' => $date,
                        ]);
                    } else {
                        $fullUser = User::find($user->id);
                        if ($fullUser) {
                            MorinogNotificationJob::dispatch($fullUser, $args)->onQueue('sms');
                            $dispatched++;
                        }
                    }
                }
            }
        }

        Log::info('SMS blackout notifications queued', [
            'date' => $date,
            'count' => $dispatched,
        ]);

        return self::SUCCESS;
    }
}


