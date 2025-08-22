<?php

namespace App\Console\Commands;

use App\Services\Blackout\BlackoutImporter;
use Illuminate\Console\Command;

class ImportBlackoutsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'blackouts:import';

    /**
     * The console command description.
     */
    protected $description = 'Fetch and import blackout schedules for today (Jalali) across all areas';

    public function handle(BlackoutImporter $importer): int
    {
        // Use zero-padded Jalali date like 1404/05/31 to match the site input format
        $today = \Hekmatinasser\Verta\Verta::now()->format('Y/m/d');
        $result = $importer->import($today, $today, []);
        $this->info('Import finished. Created: ' . $result['created'] . ', Updated: ' . $result['updated'] . ', Skipped: ' . $result['skipped']);
        return self::SUCCESS;
    }
}


