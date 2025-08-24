<?php

namespace App\Console\Commands;

use App\Services\Blackout\BlackoutImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportTomorrowBlackoutsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'blackouts:import-tomorrow';

    /**
     * The console command description.
     */
    protected $description = 'Fetch and import blackout schedules for tomorrow (Jalali) across all areas';

    /**
     * Execute the console command.
     */
    public function handle(BlackoutImporter $importer): int
    {
        // Use zero-padded Jalali date like 1404/05/31 to match the site input format
        $tomorrow = \Hekmatinasser\Verta\Verta::now()->addDay()->format('Y/m/d');
        $result = $importer->import($tomorrow, $tomorrow, []);
        Log::info('Import (tomorrow) finished. Created: ' . $result['created'] . ', Updated: ' . $result['updated'] . ', Skipped: ' . $result['skipped']);
        return self::SUCCESS;
    }
}


