<?php
declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Address;
use App\Models\Blackout;
use Hekmatinasser\Verta\Verta;
use Illuminate\Support\Carbon;

/**
 * Handles blackout notification formatting and sending.
 */
class BlackoutNotificationService
{
    public function __construct(
        public TelegramService $telegram,
        public UserAddressService $userAddress,
    ) {
    }

    public function notifyTodays(int|string $chatId, int $addressId): void
    {
        $today = Carbon::today()->toDateString();
        $blackouts = Blackout::query()
            ->where('address_id', $addressId)
            ->whereDate('outage_date', $today)
            ->orderBy('outage_start_time')
            ->get(['outage_start_time', 'outage_end_time', 'outage_date']);

        if ($blackouts->isEmpty()) {
            return;
        }

        $v = new Verta($today);
        $dateFa = $v->format('l j F');

        $address = Address::with('city')->find($addressId);
        $cityName = (string) ($address?->city?->name ?? '');
        $label = $address ? (string) ($address->address ?? '') : '';
        $locationLine = '📍 ' . trim(($cityName !== '' ? $cityName . ' | ' : '') . $label, ' |');

        $sections = [];
        foreach ($blackouts as $b) {
            $start = $b->outage_start_time ? Carbon::parse($b->outage_start_time)->format('H:i') : '—';
            $end = $b->outage_end_time ? Carbon::parse($b->outage_end_time)->format('H:i') : '—';
            $sections[] = '<blockquote>' . e('⏰ ' . $dateFa . ' ساعت ' . $start . ' الی ' . $end) . '</blockquote>';
        }

        $final = '📅 برنامه قطعی امروز (' . $dateFa . '):' . "\n\n"
            . e($locationLine) . "\n\n"
            . implode("\n\n", $sections);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $final,
            'parse_mode' => 'HTML',
        ]);
    }

    public function notifyTodayForAllAddresses(int|string $chatId): void
    {
        $user = $this->userAddress->findUserByChatId($chatId);
        $addresses = $user ? $user->addresses()->with('city')->get() : collect();

        if ($addresses->isEmpty()) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '📭 هنوز آدرسی اضافه نکرده‌اید.' . "\n\n" . 'برای اضافه کردن آدرس، بر روی 👈  /add_new_address  👉 بزنید' . "\n\n" . 'یا بر روی دکمه پایین 📍افزودن آدرس جدید بزنید:' . "\n\n" . '👇👇👇',
            ]);
            return;
        }

        $today = Carbon::today()->toDateString();
        $vToday = new Verta($today);
        $dateFa = $vToday->format('l j F');
        $sections = [];
        foreach ($addresses as $address) {
            $blackouts = Blackout::query()
                ->where('address_id', $address->id)
                ->whereDate('outage_date', $today)
                ->orderBy('outage_start_time')
                ->get(['outage_start_time', 'outage_end_time', 'outage_date']);

            $cityName = (string) ($address->city?->name ?? '');
            $label = (string) ($address->alias_address ?? $address->address ?? '');
            $locationLine = '📍 ' . trim(($cityName !== '' ? $cityName . ' | ' : '') . $label, ' |');

            $addressSections = [];
            if ($blackouts->isEmpty()) {
                $addressSections[] = '<blockquote>' . e('✅ امروز برای این آدرس قطعی ثبت نشده است.') . '</blockquote>';
            } else {
                foreach ($blackouts as $b) {
                    $start = $b->outage_start_time ? Carbon::parse($b->outage_start_time)->format('H:i') : '—';
                    $end = $b->outage_end_time ? Carbon::parse($b->outage_end_time)->format('H:i') : '—';
                    $addressSections[] = '<blockquote>' . e('⏰ ' . $dateFa . ' ساعت ' . $start . ' الی ' . $end) . '</blockquote>';
                }
            }

            $section = e($locationLine) . "\n\n" . implode("\n\n", $addressSections);

            if (!empty($sections)) {
                $sections[] = '🔹🔻🔻🔻🔻🔹';
            }

            $sections[] = $section;
        }

        $header = '📅 برنامه قطعی امروز (' . $dateFa . '):';
        $final = $header . "\n\n" . implode("\n\n", $sections);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $final,
            'parse_mode' => 'HTML',
        ]);
    }

    public function notifyTomorrowForAllAddresses(int|string $chatId): void
    {
        $user = $this->userAddress->findUserByChatId($chatId);
        $addresses = $user ? $user->addresses()->with('city')->get() : collect();

        if ($addresses->isEmpty()) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '📭 هنوز آدرسی اضافه نکرده‌اید.' . "\n\n" . 'برای اضافه کردن آدرس، بر روی دکمه 📍افزودن آدرس جدید بزنید:' . "\n\n" . '👇👇👇' . "\n\n" . '/add_new_address',
            ]);
            return;
        }

        $tomorrow = Carbon::tomorrow()->toDateString();
        $vTomorrow = new Verta($tomorrow);
        $dateFa = $vTomorrow->format('l j F');
        $sections = [];
        foreach ($addresses as $address) {
            $blackouts = Blackout::query()
                ->where('address_id', $address->id)
                ->whereDate('outage_date', $tomorrow)
                ->orderBy('outage_start_time')
                ->get(['outage_start_time', 'outage_end_time', 'outage_date']);

            $cityName = (string) ($address->city?->name ?? '');
            $label = (string) ($address->alias_address ?? $address->address ?? '');
            $locationLine = '📍 ' . trim(($cityName !== '' ? $cityName . ' | ' : '') . $label, ' |');

            $addressSections = [];
            if ($blackouts->isEmpty()) {
                $addressSections[] = '<blockquote>' . e('✅ برای فردا قطعی ثبت نشده است.') . '</blockquote>';
            } else {
                foreach ($blackouts as $b) {
                    $start = $b->outage_start_time ? Carbon::parse($b->outage_start_time)->format('H:i') : '—';
                    $end = $b->outage_end_time ? Carbon::parse($b->outage_end_time)->format('H:i') : '—';
                    $addressSections[] = '<blockquote>' . e('⏰ ' . $dateFa . ' ساعت ' . $start . ' الی ' . $end) . '</blockquote>';
                }
            }

            $section = e($locationLine) . "\n\n" . implode("\n\n", $addressSections);

            if (!empty($sections)) {
                $sections[] = '🔹🔻🔻🔻🔻🔹';
            }

            $sections[] = $section;
        }

        $header = '📅 برنامه قطعی فردا (' . $dateFa . '):';
        $final = $header . "\n\n" . implode("\n\n", $sections);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $final,
            'parse_mode' => 'HTML',
        ]);
    }
}


