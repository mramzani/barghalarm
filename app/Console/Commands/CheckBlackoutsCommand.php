<?php

namespace App\Console\Commands;

use App\Jobs\SendBlackoutNotificationsForUser;
use App\Models\User;
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

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $date = (string) ($this->option('date') ?: Carbon::today()->toDateString());

        $dispatchedJobs = 0;

        User::query()
            ->where('is_verified', true)
            ->where('is_active', true)
            ->whereNotNull('chat_id')
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($date, &$dispatchedJobs): void {
                foreach ($users as $user) {
                    SendBlackoutNotificationsForUser::dispatch($user->id, $date);
                    $dispatchedJobs++;
                }
            });

        Log::info('Dispatched blackout notification jobs for users: ' . $dispatchedJobs, ['date' => $date]);
        return self::SUCCESS;
    }
}


