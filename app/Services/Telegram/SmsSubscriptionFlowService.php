<?php
declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Subscription;
use App\Services\Billing\SubscriptionBillingService;
use Hekmatinasser\Verta\Verta;
use Illuminate\Support\Carbon;

/**
 * Handles SMS subscription consent, naming flow, and invoice preview.
 */
class SmsSubscriptionFlowService
{
    public function __construct(
        public TelegramService $telegram,
        public StateStore $state,
        public MenuService $menu,
        public UserAddressService $userAddress,
        public SubscriptionBillingService $billing,
    ) {
    }

    /**
     * Begin purchase flow from main menu.
     */
    public function beginPurchase(int|string $chatId): void
    {
        $user = $this->userAddress->findUserByChatId($chatId);
        $uncovered = $user ? $this->billing->getUncoveredAddressIds($user) : [];
        $count = count($uncovered);

        if ($count === 0) {
            $maxEnd = Subscription::query()
                ->where('user_id', $user?->id)
                ->where('status', 'active')
                ->max('ends_on');
            $endsFa = $maxEnd ? (new Verta(Carbon::parse($maxEnd)))->format('Y/m/d') : '-';
            $msg = '✅ شما برای تمام آدرس‌های خود اشتراک فعال دارید.' . "\n" . '⏳ اعتبار اشتراک‌ها تا: ' . $endsFa;
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $msg,
            ]);
            $this->menu->sendMainMenu($chatId);
            return;
        }

        $this->sendConsent($chatId);
    }

    /**
     * Send consent/terms message.
     */
    public function sendConsent(int|string $chatId): void
    {
        $consent = "✅ دوست داری به‌جای اینکه هی تلگرام رو چک کنی، هر روز صبح و ۲۰ دقیقه قبل از قطعی برق، با یه پیامک باخبر بشی؟\n"
            . "🔹 چون ارسال پیامک یه کم هزینه داره، باید اشتراک VIP بگیری. این اشتراک فقط ماهی ۳۰,۰۰۰ تومن (برای هر آدرس، روزی ۱۰۰۰ تومن)ه که همون هزینه پیامک‌های یه ماهه‌ست.\n"
            . "چندتا نکته مهم (لطفاً با دقت بخون):\n\n"
            . "هزینه اشتراک بستگی به تعداد آدرس‌هایی داره که ثبت کردی. اگه آدرس اشتباه یا اضافی وارد کردی، حتماً حذفش کن. چون هزینه اضافی برنمی‌گرده!\n"
            . "اطلاعات ربات ما هر روز ۴ تا ۶ بار از سامانه شرکت توزیع برق مازندران به‌روزرسانی می‌شه. اگه ساعت قطعی برق اشتباه اعلام بشه، ما مسئولش نیستیم.\n"
            . "این ربات تا وقتی سامانه شرکت توزیع برق اطلاعات بده کار می‌کنه. اگه دسترسی محدود بشه، ممکنه ربات از کار بیفته.\n"
            . "تو مرحله بعد، شماره موبایلت رو می‌پرسیم. اگه شماره رو اشتباه وارد کنی، مسئولیت با خودته و هزینه هم برنمی‌گرده.\n"
            . "با پرداخت و خرید اشتراک، یعنی همه این قوانین رو قبول کردی!\n\n"
            . "خب، آماده‌ای که باهم شروع کنیم؟ 😊";

        $consentButtons = [
            [
                $this->telegram->buildInlineKeyboardButton('مطالعه کردم و قبول دارم', '', 'SMS_TERMS_OK'),
            ],
        ];

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $consent,
            'reply_markup' => $this->telegram->buildInlineKeyBoard($consentButtons),
        ]);
    }

    /**
     * After user accepted terms.
     */
    public function proceedAfterConsent(int|string $chatId): void
    {
        $user = $this->userAddress->findUserByChatId($chatId);
        $uncovered = $user ? $this->billing->getUncoveredAddressIds($user) : [];
        $count = count($uncovered);

        if ($count === 0) {
            $maxEnd = Subscription::query()
                ->where('user_id', $user?->id)
                ->where('status', 'active')
                ->max('ends_on');
            $endsFa = $maxEnd ? (new Verta(Carbon::parse($maxEnd)))->format('Y/m/d') : '-';
            $msg = '✅ شما برای تمام آدرس‌های خود اشتراک فعال دارید.' . "\n" . '⏳ اعتبار اشتراک‌ها تا: ' . $endsFa;
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $msg,
            ]);
            return;
        }

        $addresses = $user?->addresses()->with('city')->whereIn('addresses.id', $uncovered)->get();
        $needsNames = ($addresses ?? collect())->filter(function ($addr) {
            return empty($addr->pivot->name ?? null);
        });

        if ($needsNames->count() > 0) {
            $queue = $needsNames->pluck('id')->all();
            $this->state->set($chatId, ['step' => 'sms_name_flow', 'queue' => $queue, 'pos' => 0, 'uncovered' => $uncovered]);
            $this->promptNextSmsName($chatId);
            return;
        }

        $this->sendInvoicePreview($chatId, $user, $uncovered);
    }

    /**
     * Handle a name text during sms_name_flow.
     *
     * @param array<string,mixed> $state
     */
    public function handleNameFlowText(int|string $chatId, array $state, string $text): void
    {
        if ($text === 'انصراف') {
            $this->state->clear($chatId);
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ نام‌گذاری لغو شد.',
            ]);
            $this->menu->hideReplyKeyboard($chatId);
            $this->menu->sendMainMenu($chatId);
            return;
        }

        if ($text === 'انصراف از خرید') {
            $this->cancelPurchase($chatId);
            return;
        }

        // Ignore main menu button texts during naming flow to prevent accidental aliasing
        $mainMenuButtons = [
            '💬 دریافت هشدار با SMS',
            '🗂️ آدرس‌های من',
            '📍️ افزودن آدرس جدید',
            '📆 قطعی‌های فردا',
            '🔴 قطعی‌های امروز',
            '💡 درباره ما',
            '📨 پیشنهاد یا گزارش مشکل',
            '👤 مدیریت ربات',
            '↩️ بازگشت به منو اصلی',
        ];
        if (in_array($text, $mainMenuButtons, true)) {
            $this->promptNextSmsName($chatId);
            return;
        }

        $queue = (array) $state['queue'];
        $pos = (int) $state['pos'];
        $uncovered = (array) ($state['uncovered'] ?? []);

        if (isset($queue[$pos])) {
            $this->userAddress->setAddressAlias($chatId, (int) $queue[$pos], trim($text));
        }

        $pos++;
        $this->state->set($chatId, ['step' => 'sms_name_flow', 'queue' => $queue, 'pos' => $pos, 'uncovered' => $uncovered]);
        $this->promptNextSmsName($chatId);
    }

    /**
     * Prompt next address name during sms_name_flow.
     */
    public function promptNextSmsName(int|string $chatId): void
    {
        $state = $this->state->get($chatId);
        $queue = (array) ($state['queue'] ?? []);
        $pos = (int) ($state['pos'] ?? 0);
        $uncovered = (array) ($state['uncovered'] ?? []);

        $user = $this->userAddress->findUserByChatId($chatId);
        if (!$user) {
            $this->state->clear($chatId);
            return;
        }

        if ($pos >= count($queue)) {
            $this->state->clear($chatId);
            $user = $this->userAddress->findUserByChatId($chatId);
            $uncovered = (array) ($state['uncovered'] ?? []);
            $this->sendInvoicePreview($chatId, $user, $uncovered);
            return;
        }

        $addressId = (int) $queue[$pos];
        $address = $user->addresses()->with('city')->where('addresses.id', $addressId)->first();
        if (!$address) {
            $pos++;
            $this->state->set($chatId, ['step' => 'sms_name_flow', 'queue' => $queue, 'pos' => $pos, 'uncovered' => $uncovered]);
            $this->promptNextSmsName($chatId);
            return;
        }

        if (!empty($address->pivot->name ?? null)) {
            $pos++;
            $this->state->set($chatId, ['step' => 'sms_name_flow', 'queue' => $queue, 'pos' => $pos, 'uncovered' => $uncovered]);
            $this->promptNextSmsName($chatId);
            return;
        }

        $cityName = (string) ($address->city?->name ?? '');
        $pivotAlias = is_string($address->pivot->name ?? null) ? trim((string) $address->pivot->name) : '';
        $label = $pivotAlias !== '' ? $pivotAlias : (string) ($address->address ?? '');
        $locationLine = '📍 ' . trim(($cityName !== '' ? $cityName . ' | ' : '') . $label, ' |');

        // Replace main menu with a minimal reply keyboard that only contains 'انصراف'
        $keyboard = [
            [
                $this->telegram->buildKeyboardButton('انصراف'),
            ],
        ];
        $replyKeyboard = $this->telegram->buildKeyBoard($keyboard, true, true, true);
        $this->state->set($chatId, ['step' => 'sms_name_flow', 'queue' => $queue, 'pos' => $pos, 'uncovered' => $uncovered, 'address_id' => $addressId]);
        // Hide the persistent reply keyboard BEFORE asking for free-text to avoid menu presses becoming the name
        $this->menu->hideReplyKeyboard($chatId);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $locationLine . "\n\n" . 'یک نام کوتاه برای این آدرس بنویس (مثلاً: خانه، دفتر، مغازه).',
            'reply_markup' => $replyKeyboard,
        ]);
    }

    /**
     * Send invoice preview.
     *
     * @param array<int,int> $uncovered
     */
    public function sendInvoicePreview(int|string $chatId, $user, array $uncovered): void
    {
        $count = count($uncovered);
        if ($count <= 0) {
            return;
        }

        // Ensure the previous reply keyboard (e.g., 'انصراف') is fully removed and replaced
        $this->menu->hideReplyKeyboard($chatId);
        $this->menu->sendMainMenuWithoutIntro($chatId);

        $addresses = $user?->addresses()->with('city')->whereIn('addresses.id', $uncovered)->get();
        $addressLines = [];
        foreach ($addresses ?? [] as $addr) {
            $cityName = (string) ($addr->city?->name ?? '');
            $pivotAlias = is_string($addr->pivot->name ?? null) ? trim((string) $addr->pivot->name) : '';
            $label = $pivotAlias !== '' ? $pivotAlias : (string) ($addr->address ?? '');
            $addressLines[] = '<blockquote>' . e(trim(($cityName !== '' ? $cityName . ' | ' : '') . $label, ' |')) . '</blockquote>';
        }

        $pricePer = SubscriptionBillingService::PRICE_PER_ADDRESS;
        $monthly = $count * $pricePer;
        $daily = (int) ceil($monthly / 30);
        $smsPerDay = 2;

        $body = [];
        $body[] = '📍شما در حال خرید اشتراک برای آدرس:';
        if (!empty($addressLines)) {
            $body[] = implode("\n", $addressLines);
        }
        $body[] = '📬 سرویس «هشدار پیامکی قطعی برق»';
        $body[] = '👤 کاربر: ' . $chatId;
        $body[] = '📍 آدرس‌های بدون اشتراک: ' . $count;
        $body[] = '💵 هزینه ماهانه هر آدرس: ' . number_format($pricePer) . ' تومان';
        $body[] = '🧮 جمع ماهانه قابل پرداخت: ' . number_format($monthly) . ' تومان';
        $body[] = '📅 معادل روزانه: ' . number_format($daily) . ' تومان | ~' . $smsPerDay . ' پیامک';
        $preview = implode("\n", $body);

        $invoiceUrl = route('payments.invoice', ['chat_id' => $chatId]);
        $buttons = [
            [
                $this->telegram->buildInlineKeyboardButton('ادامه و پرداخت', $invoiceUrl, ''),
            ],
            [
                $this->telegram->buildInlineKeyboardButton('انصراف از خرید', '', 'SMS_CANCEL'),
            ],
        ];
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $preview . "\n\n" . 'جهت خرید اشتراک روی دکمه‌ی زیر کلیک کنید👇👇👇',
            'reply_markup' => $this->telegram->buildInlineKeyBoard($buttons),
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * Cancel purchase and reset UI.
     */
    public function cancelPurchase(int|string $chatId): void
    {
        $this->state->clear($chatId);
        $this->menu->hideReplyKeyboard($chatId);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'خرید اشتراک پیامکی لغو شد. برای شروع دوباره، «💬 دریافت هشدار با SMS» را انتخاب کنید.',
        ]);
        $this->menu->sendMainMenu($chatId);
    }
}


