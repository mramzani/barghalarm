<?php

namespace App\Console\Commands;

use App\Models\Address;
use App\Models\City;
use App\Services\Scraper\OutageScraper;
use Illuminate\Console\Command;

class ImportNewAddressCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blackouts:import-new-address';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import new address from blackouts';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $cities = City::all();
        $scraper = new OutageScraper();
        $today = \Hekmatinasser\Verta\Verta::now()->format('Y/m/d');
        foreach ($cities as $city) {
            foreach ($city->areas as $area) {
                $blackouts = $scraper->searchOutages($today, $today, $area->code);
                $blackouts = array_key_exists(1, $blackouts) ? $blackouts[1] : [];
                if (count($blackouts) > 1) {
                    foreach ($blackouts as $key => $blackout) {
                        $address = Address::where('address', $blackout[4])->first();

                        if ($address) continue;

                        $addressCode = (int) ($city->id . "00" . $key);
                        $address = Address::create([
                            'city_id' => $city->id,
                            'address' => $blackout[4],
                            'code' => $addressCode,
                        ]);
                    }
                }
            }
        }
        return self::SUCCESS;
    }
}
