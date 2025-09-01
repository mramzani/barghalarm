<?php

namespace App\Console\Commands;

use App\Models\Blackout;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PruneOldBlackoutsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'blackouts:prune';

    /**
     * The console command description.
     */
    protected $description = 'Delete blackout records for days before today';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $today = Carbon::today();

        // Use whereDate to compare date-only column; keep today and future
        $deleted = Blackout::query()
            ->whereDate('outage_date', '<', $today->toDateString())
            ->delete();

        $this->info('Deleted old blackouts: ' . $deleted);

        return self::SUCCESS;
    }
}


