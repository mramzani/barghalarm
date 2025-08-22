<?php

use App\Http\Controllers\Api\TelegramController;
use App\Models\Address;
use App\Models\Area;
use App\Models\Blackout;
use App\Models\City;
use App\Services\Scraper\OutageScraper;
use App\Services\Telegram\Telegram;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Hekmatinasser\Verta\Verta;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/


// Route::get('/save-address', function () {
//     $cities = City::all();
//     $scraper = new OutageScraper();
//     foreach ($cities as $city) {
//         foreach ($city->areas as $area) {
//             $blackouts = $scraper->searchOutages('1404/05/31', '1404/05/31', $area->code);
//             $blackouts = array_key_exists(1, $blackouts) ? $blackouts[1] : [];
//             if (count($blackouts) > 1) {
//                 foreach ($blackouts as $key => $blackout) {
//                     $address = Address::where('address', $blackout[4])->first();

//                     if ($address) continue;

//                     $addressCode = (int) ($city->id . "00" . $key);
//                     $address = Address::create([
//                         'city_id' => $city->id,
//                         'address' => $blackout[4],
//                         'code' => $addressCode,
//                     ]);
//                 }
//             }
//         }
//     }
//     return "done";
// });

// Legacy route disabled in favor of scheduled command (blackouts:import)
/* Route::get('/save-blackout', function () {
    $areas = Area::all();
    $scraper = new OutageScraper();

    $generateOutageNumber = static function (int $areaId, int $cityId, int $addressId, ?string $outageDate, ?string $outageStartTime): int {
        $dateNormalized = $outageDate ? preg_replace('/[^0-9]/', '', $outageDate) : '0';
        $timeNormalized = $outageStartTime ? preg_replace('/[^0-9]/', '', $outageStartTime) : '0';
        $key = implode('|', [
            $areaId,
            $cityId,
            $addressId,
            $dateNormalized,
            $timeNormalized,
        ]);

        $hex = substr(sha1($key), 0, 15); // ~60 bits
        $decimalString = base_convert($hex, 16, 10);
        $number = (int) $decimalString;
        if ($number === 0) {
            $fallbackHex = substr(sha1($key . '!'), 0, 15);
            $number = (int) base_convert($fallbackHex, 16, 10);
            if ($number === 0) {
                $number = 1;
            }
        }
        return $number;
    };

    foreach ($areas as $area) {
        $blackouts = $scraper->searchOutages('1404/05/31', '1404/05/31', $area->code);
        $blackouts = array_key_exists(1, $blackouts) ? $blackouts[1] : [];
        if (count($blackouts) > 1) {
            foreach ($blackouts as $blackout) {
                $addressId = $scraper->getAddressId($blackout, $area);

                if (!$addressId) continue;
                $date = array_key_exists(0, $blackout) ? $blackout[0] : null;
                // Convert Jalali date to Gregorian using Verta
                $gregorianDate = null;
                if (!empty($date)) {
                    $parts = preg_split('/[\/-]/', $date);
                    $jy = isset($parts[0]) ? (int) $parts[0] : null;
                    $jm = isset($parts[1]) ? (int) $parts[1] : null;
                    $jd = isset($parts[2]) ? (int) $parts[2] : null;
                    if ($jy && $jm && $jd) {
                        [$gy, $gm, $gd] = Verta::jalaliToGregorian($jy, $jm, $jd);
                        $gregorianDate = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
                    }
                }
                $startTime = array_key_exists(1, $blackout) ? Carbon::parse($blackout[1])->format('H:i') : null;

                Blackout::create([
                    'area_id' => $area->id,
                    'city_id' => $area->city_id,
                    'address_id' => $addressId,
                    'outage_start_time' => $startTime,
                    'outage_date' => $gregorianDate,
                    'outage_end_time' => array_key_exists(2, $blackout) ? Carbon::parse($blackout[2])->format('H:i') : null,
                    'outage_number' => $generateOutageNumber($area->id, $area->city_id, $addressId, $gregorianDate, $startTime),
                ]);
                // dd($blackoutModel->toArray());
            }
        }
    }
}); */


Route::post('/telegram/bot', [TelegramController::class, 'handle']);
Route::get('/info', [TelegramController::class, 'webhookInfo']);
//Route::get('/set', [TelegramController::class, 'setWebhook']);

