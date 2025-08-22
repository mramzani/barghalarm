<?php

namespace App\Services\Telegram;

use App\Models\Address;
use App\Models\City;

class AddressFlowService
{
    public function __construct(
        public TelegramService $telegram,
        public StateStore $stateStore,
        public MenuService $menu,
    ) {}

    public function showAddAddressFlow(int|string $chatId): void
    {
        $this->menu->hideReplyKeyboard($chatId);
        $cities = City::orderBy('name_fa')->get(['id', 'name_fa']);
        $buttons = [];
        $row = [];
        $perRow = 3;
        foreach ($cities as $index => $city) {
            $row[] = $this->telegram->buildInlineKeyboardButton($city->name_fa, '', 'CITY_'.$city->id);
            if ((count($row) === $perRow)) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (count($row) > 0) {
            $buttons[] = $row;
        }
        // $buttons[] = [
        //     $this->telegram->buildInlineKeyboardButton('بازگشت', '', 'BACK_TO_MENU'),
        // ];
        $buttons[] = [
            $this->telegram->buildInlineKeyboardButton('بازگشت به منو اصلی ⬅️', '', 'BACK_TO_MENU'),
        ];
        $replyMarkup = $this->telegram->buildInlineKeyBoard($buttons);

        $message = "مسیر: شروع › شهرها\n\n".'یکی از شهرها را انتخاب کن تا آدرس رو با هم اضافه کنیم ✨';
        $this->sendOrEdit($chatId, $message, $replyMarkup);
    }

    public function promptForKeyword(int|string $chatId, int $cityId): void
    {
        $this->menu->hideReplyKeyboard($chatId);
        $city = City::find($cityId);
        if (! $city) {
            $this->showAddAddressFlow($chatId);

            return;
        }

        $this->stateStore->set($chatId, ['step' => 'await_keyword', 'city_id' => $cityId]);

        $buttons = [
            [
                $this->telegram->buildInlineKeyboardButton('بازگشت', '', 'BACK_TO_ADD'),
            ], [
                $this->telegram->buildInlineKeyboardButton('بازگشت به منو اصلی ⬅️', '', 'BACK_TO_MENU'),
            ],
        ];
        $replyMarkup = $this->telegram->buildInlineKeyBoard($buttons);

        $message = 'مسیر: شهرها › جستجو'."\n\n".'🏙️ شهر انتخابی: '.$city->name()."\n\n".
            '🔍 لطفاً یک کلمه از آدرس مورد نظرت رو برای جستجو بفرست (نام خیابان، محله یا منطقه).'."\n\n".
            '💡 تو این شهر هر آدرسی که کلمه‌ی مورد نظرت توش باشه رو برات پیدا می‌کنم و نشون می‌دم!'."\n\n".
            '🔍 هر چی کلمه‌ای که بفرستی کوتاه تر باشه جستجو بهتره و سریع تره!';

        $this->sendOrEdit($chatId, $message, $replyMarkup);
    }

    public function handleKeywordSearch(int|string $chatId, int $cityId, string $keyword): void
    {
        // Normalize keyword: collapse multiple spaces and trim
        $normalizedKeyword = trim(preg_replace('/\s+/u', ' ', $keyword));
        $words = array_values(array_filter(explode(' ', $normalizedKeyword), static function ($word) {
            return $word !== '';
        }));

        // Build an AND-based per-word match first (more precise), limited to this city
        $baseQuery = Address::query()
            ->where('city_id', $cityId)
            ->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->where('address', 'like', '%'.$word.'%');
                }
            })
            ->limit(10);

        $results = $baseQuery->get(['id', 'address']);

        // If nothing found and there are multiple words, try a broader OR-based match as a fallback
        if ($results->isEmpty() && count($words) > 1) {
            $fallbackQuery = Address::query()
                ->where('city_id', $cityId)
                ->where(function ($q) use ($words) {
                    foreach ($words as $word) {
                        $q->orWhere('address', 'like', '%'.$word.'%');
                    }
                })
                ->limit(10);

            $results = $fallbackQuery->get(['id', 'address']);
        }

        $city = City::find($cityId);
        $cityName = $city ? $city->name() : '';

        $emojiNumbers = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟'];

        $lines = [];
        $kbRows = [];

        // Prepare a highlighting pattern for all words (longer words first)
        $sortedWords = $words;
        usort($sortedWords, static function ($a, $b) {
            return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
        });
        $quotedWords = array_map(static function ($w) {
            return preg_quote($w, '/');
        }, $sortedWords);
        $highlightPattern = $quotedWords === [] ? null : '/('.implode('|', $quotedWords).')/iu';

        foreach ($results as $index => $addr) {
            // Build list line with emoji and highlighted keyword
            $emoji = $emojiNumbers[$index] ?? (($index + 1).'️⃣');
            $escapedAddress = htmlspecialchars($addr->address, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $highlighted = $highlightPattern ? preg_replace($highlightPattern, '<b>$1</b>', $escapedAddress) : $escapedAddress;
            $lines[] = $emoji.' '.$highlighted;

            // Build one-button-per-row keyboard
            $label = (function (string $text) use ($emoji): string {
                $t = $text;
                if (function_exists('mb_strimwidth')) {
                    $t = mb_strimwidth($t, 0, 48, '…', 'UTF-8');
                } else {
                    $t = substr($t, 0, 48).(strlen($t) > 48 ? '…' : '');
                }

                return $emoji.' '.$t;
            })($addr->address);

            $kbRows[] = [
                $this->telegram->buildInlineKeyboardButton($label, '', 'ADDR_'.$addr->id),
            ];
        }

        // Bottom actions: Back and Search Again
        $kbRows[] = [
            $this->telegram->buildInlineKeyboardButton('↩️ بازگشت', '', 'BACK_TO_ADD'),
            $this->telegram->buildInlineKeyboardButton('🔎 جستجوی جدید', '', 'SEARCH_AGAIN'),
        ];

        $replyMarkup = $this->telegram->buildInlineKeyBoard($kbRows);

        $escapedKeyword = htmlspecialchars($keyword, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedCity = htmlspecialchars($cityName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $countLabel = count($results) > 0 ? ' ('.count($results).' مورد)' : '';
        $header = '<b>مسیر:</b> شهرها › جستجو › نتایج'."\n\n"
            .'🔍 نتایج برای «'.$escapedKeyword.'» در <b>'.$escapedCity.'</b>'.$countLabel.':';

        $body = count($lines) === 0
            ? 'هیچ آدرسی با این کلمه پیدا نشد.'
            : implode("\n", $lines);

        $this->sendOrEdit($chatId, $header."\n\n".$body, $replyMarkup);
    }

    public function sendOrEdit(int|string $chatId, string $text, ?string $replyMarkup): void
    {
        if ($this->telegram->getUpdateType() === TelegramService::CALLBACK_QUERY) {
            $messageId = $this->telegram->MessageID();
            $payload = [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ];
            if (! is_null($replyMarkup)) {
                $payload['reply_markup'] = $replyMarkup;
            }
            $this->telegram->editMessageText($payload);
        } else {
            $msg = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ];
            if (! is_null($replyMarkup)) {
                $msg['reply_markup'] = $replyMarkup;
            }
            $this->telegram->sendMessage($msg);
        }
    }
}
