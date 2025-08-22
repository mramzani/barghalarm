<?php

namespace App\Services\Blackout;

use App\Models\Area;
use App\Models\Blackout;
use App\Services\Scraper\OutageScraper;
use Hekmatinasser\Verta\Verta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class BlackoutImporter
{
    public function __construct(
        protected OutageScraper $scraper = new OutageScraper()
    ) {
    }

    /**
     * Import blackout schedules for a date range (Jalali) across areas or a subset.
     *
     * @param string $dateFromJalali Y/m/d
     * @param string $dateToJalali Y/m/d
     * @param array<int,string> $areaCodes Optional list of area codes to limit import
     * @return array{created:int,updated:int,skipped:int}
     */
    public function import(string $dateFromJalali, string $dateToJalali, array $areaCodes = []): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        $areas = Area::query()
            ->when(!empty($areaCodes), function ($q) use ($areaCodes) {
                return $q->whereIn('code', $areaCodes);
            })
            ->get();

        foreach ($areas as $area) {
            [$headers, $rows] = $this->scraper->searchOutages($dateFromJalali, $dateToJalali, (string) $area->code);
            // Remove header row if present as first row
            if (!empty($headers) && !empty($rows) && $rows[0] === $headers) {
                array_shift($rows);
            }
            if (empty($rows)) {
                continue;
            }

            foreach ($rows as $blackoutRow) {
                // Expect columns: 0=date(Jalali), 1=start, 2=end, 4=address text
                $addressId = $this->scraper->getAddressId($blackoutRow, $area);
                if (!$addressId) {
                    $skipped++;
                    continue;
                }

                $dateJalali = array_key_exists(0, $blackoutRow) ? trim((string) $blackoutRow[0]) : null;
                $startTime = array_key_exists(1, $blackoutRow) && trim((string) $blackoutRow[1]) !== ''
                    ? Carbon::parse($blackoutRow[1])->format('H:i')
                    : null;
                $endTime = array_key_exists(2, $blackoutRow) && trim((string) $blackoutRow[2]) !== ''
                    ? Carbon::parse($blackoutRow[2])->format('H:i')
                    : null;

                $gregorianDate = $this->convertJalaliToGregorian($dateJalali);
                $outageNumber = $this->generateOutageNumber($area->id, (int) $area->city_id, $addressId, $gregorianDate, $startTime);

                $values = [
                    'area_id' => $area->id,
                    'city_id' => $area->city_id,
                    'address_id' => $addressId,
                    'outage_date' => $gregorianDate,
                    'outage_start_time' => $startTime,
                    'outage_end_time' => $endTime,
                ];

                $existing = Blackout::where('outage_number', $outageNumber)->first();
                if ($existing) {
                    $existing->fill($values);
                    if ($existing->isDirty()) {
                        $existing->save();
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } else {
                    $values['outage_number'] = $outageNumber;
                    Blackout::create($values);
                    $created++;
                }
            }
        }

        return compact('created', 'updated', 'skipped');
    }

    private function convertJalaliToGregorian(?string $jalaliDate): ?string
    {
        if (empty($jalaliDate)) {
            return null;
        }
        $parts = preg_split('/[\/-]/', $jalaliDate);
        $jy = isset($parts[0]) ? (int) $parts[0] : null;
        $jm = isset($parts[1]) ? (int) $parts[1] : null;
        $jd = isset($parts[2]) ? (int) $parts[2] : null;
        if ($jy && $jm && $jd) {
            [$gy, $gm, $gd] = Verta::jalaliToGregorian($jy, $jm, $jd);
            return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
        }
        return null;
    }

    private function generateOutageNumber(int $areaId, int $cityId, int $addressId, ?string $outageDate, ?string $outageStartTime): int
    {
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
    }
}


