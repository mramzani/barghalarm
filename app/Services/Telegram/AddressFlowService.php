<?php

namespace App\Services\Telegram;

use App\Models\Address;
use App\Models\City;
use Illuminate\Support\Facades\Log;

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
        // Normalize keyword to align Arabic/Persian variants and remove diacritics/zero-width chars
        $normalizedKeyword = $this->normalizeForSearch($keyword);
        $words = array_values(array_filter(explode(' ', $normalizedKeyword), static function ($word) {
            return $word !== '';
        }));

        // Tiered search to reduce noise:
        // 1) Tight phrase match: compare after removing spaces/dashes/ZWNJ/tatweel + char mapping
        // 2) Phrase-order match: %word1%word2% using normalized column (char mapping)
        // 3) Loose phrase match: simple LIKE on normalized column
        // 4) AND per-word match on normalized column
        // 5) OR per-word fallback (only if still zero results)

        $tightKeyword = preg_replace('/[\s\-]+/u', '', $normalizedKeyword);

        $colTightSql = $this->normalizedAddressSqlTight();
        $colLooseSql = $this->normalizedAddressSqlLoose();

        $results = collect();

        // 1) Tight phrase match
        $q1 = Address::query()
            ->where('city_id', $cityId)
            ->whereRaw($colTightSql.' LIKE ?', ['%'.$tightKeyword.'%'])
            ->limit(10)
            ->get(['id', 'address']);
        $results = $results->merge($q1)->unique('id');

        // 2) Phrase-order match (%w1%w2%...) on normalized column
        if ($results->count() < 10 && count($words) > 1) {
            $phrasePattern = '%'.implode('%', $words).'%';
            $q2 = Address::query()
                ->where('city_id', $cityId)
                ->whereRaw($colLooseSql.' LIKE ?', [$phrasePattern])
                ->limit(10 - $results->count())
                ->get(['id', 'address']);
            $results = $results->merge($q2)->unique('id');
        }

        // 3) Loose phrase match on normalized column
        if ($results->count() < 10 && $normalizedKeyword !== '') {
            $q3 = Address::query()
                ->where('city_id', $cityId)
                ->whereRaw($colLooseSql.' LIKE ?', ['%'.$normalizedKeyword.'%'])
                ->limit(10 - $results->count())
                ->get(['id', 'address']);
            $results = $results->merge($q3)->unique('id');
        }

        // 4) AND per-word match
        if ($results->count() < 10 && count($words) > 0) {
            $q4 = Address::query()
                ->where('city_id', $cityId)
                ->where(function ($q) use ($words, $colLooseSql) {
                    foreach ($words as $word) {
                        $q->whereRaw($colLooseSql.' LIKE ?', ['%'.$word.'%']);
                    }
                })
                ->limit(10 - $results->count())
                ->get(['id', 'address']);
            $results = $results->merge($q4)->unique('id');
        }

        // 5) OR per-word fallback — only if هنوز هیچ نتیجه‌ای نداریم
        if ($results->count() === 0 && count($words) > 1) {
            $q5 = Address::query()
                ->where('city_id', $cityId)
                ->where(function ($q) use ($words, $colLooseSql) {
                    foreach ($words as $word) {
                        $q->orWhereRaw($colLooseSql.' LIKE ?', ['%'.$word.'%']);
                    }
                })
                ->limit(10)
                ->get(['id', 'address']);
            $results = $results->merge($q5)->unique('id');
        }

        $results = $results->take(10);

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
                    $t = mb_strimwidth($t, 0, 20, '…', 'UTF-8');
                } else {
                    $t = substr($t, 0, 20).(strlen($t) > 20 ? '…' : '');
                }

                return '✅ انتخاب آدرس '.$emoji.' '.$t;
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
            ? '⚠️ هیچ آدرسی با این کلمه پیدا نشد.' . "\n\n" . "💡پیشنهاد: کلمه رو کوتاه‌تر بنویس یا جداجدا بنویس یا بدون فاصله بنویس"
            : implode("\n", $lines)."\n\n".'از بین نتایج بالا، یک رو انتخاب کن و روی عددش این پایین بزن 👇👇👇';

        $this->sendOrEdit($chatId, $header."\n\n".$body, $replyMarkup);
    }

    /**
     * Normalize user-provided search text so it matches database content written with Arabic forms.
     *
     * - Converts Persian variants (e.g., ی, ک) to Arabic forms (ي, ك)
     * - Normalizes alef/hamza forms to simple ا, و, ي as appropriate
     * - Removes Arabic diacritics and tatweel
     * - Removes zero-width joiners and extra spaces
     */
    protected function normalizeForSearch(string $text): string
    {
        // Map Persian letters and common variants to Arabic forms frequently found in datasets
        $charMap = [
            'ی' => 'ي', // Farsi Yeh → Arabic Yeh
            'ك' => 'ك', // keep Arabic Kaf as-is
            'ک' => 'ك', // Keheh → Arabic Kaf
            'ؤ' => 'و', // Waw with Hamza → Waw
            'ئ' => 'ي', // Yeh with Hamza → Yeh
            'ى' => 'ي', // Alef Maqsura → Yeh
            'أ' => 'ا', // Alef with Hamza Above → Alef
            'إ' => 'ا', // Alef with Hamza Below → Alef
            'ٱ' => 'ا', // Alef Wasla → Alef
            'ة' => 'ه', // Teh Marbuta → Heh (for broad matching)
            'ۀ' => 'ه', // Heh with Yeh above → Heh
        ];

        // Replace mapped characters
        $normalized = strtr($text, $charMap);

        // Remove tatweel and Arabic diacritics
        $normalized = preg_replace('/[\x{0640}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $normalized);

        // Remove zero-width characters (ZWNJ, ZWJ, LRM, RLM, etc.)
        $normalized = preg_replace('/[\x{200C}\x{200D}\x{200E}\x{200F}\x{202A}-\x{202E}]/u', '', $normalized);

        // Collapse multiple spaces and trim
        $normalized = trim(preg_replace('/\s+/u', ' ', $normalized));

        return $normalized;
    }

    /**
     * SQL expression that normalizes the address column by removing spaces, dashes,
     * ZWNJ and tatweel for tight phrase matching in the database.
     */
    protected function normalizedAddressSqlTight(): string
    {
        // Apply character mapping then remove spaces, dashes, ZWNJ and tatweel
        // Keep the ZWNJ (U+200C) and tatweel (U+0640) literals as-is inside quotes.
        $expr = $this->normalizedAddressSqlLoose();
        return "REPLACE(REPLACE(REPLACE(REPLACE(($expr), ' ', ''), '-', ''), '‌', ''), 'ـ', '')";
    }

    /**
     * SQL expression that normalizes the address column by mapping Persian letters
     * to Arabic forms to match input normalization.
     */
    protected function normalizedAddressSqlLoose(): string
    {
        $expr = 'address';
        // Chain REPLACE to map key characters similar to normalizeForSearch()
        $expr = "REPLACE($expr, 'ی', 'ي')"; // Farsi Yeh -> Arabic Yeh
        $expr = "REPLACE($expr, 'ک', 'ك')"; // Keheh -> Arabic Kaf
        $expr = "REPLACE($expr, 'ؤ', 'و')"; // Waw with Hamza -> Waw
        $expr = "REPLACE($expr, 'ئ', 'ي')"; // Yeh with Hamza -> Yeh
        $expr = "REPLACE($expr, 'ى', 'ي')"; // Alef Maqsura -> Yeh
        $expr = "REPLACE($expr, 'أ', 'ا')"; // Alef with Hamza Above -> Alef
        $expr = "REPLACE($expr, 'إ', 'ا')"; // Alef with Hamza Below -> Alef
        $expr = "REPLACE($expr, 'ٱ', 'ا')"; // Alef Wasla -> Alef
        $expr = "REPLACE($expr, 'ة', 'ه')"; // Teh Marbuta -> Heh
        $expr = "REPLACE($expr, 'ۀ', 'ه')"; // Heh with Yeh above -> Heh
        // Remove tatweel and ZWNJ to reduce noise
        $expr = "REPLACE($expr, 'ـ', '')";  // tatweel
        $expr = "REPLACE($expr, '‌', '')";  // ZWNJ
        return $expr;
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
