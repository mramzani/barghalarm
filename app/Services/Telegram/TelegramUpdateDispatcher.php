<?php

namespace App\Services\Telegram;

use App\Models\Address;
use App\Models\Blackout;
use Illuminate\Support\Carbon;
use Hekmatinasser\Verta\Verta;
use Illuminate\Support\Facades\Log;

/**
 * Coordinates Telegram update handling by delegating to smaller services.
 * Maintains the existing behavior of TelegramController::handle.
 */
class TelegramUpdateDispatcher
{
    public function __construct(
        public TelegramService $telegram,
        public StateStore $state,
        public PhoneNumberNormalizer $phone,
        public MenuService $menu,
        public AddressFlowService $addressFlow,
        public UserAddressService $userAddress,
    ) {
    }

    public function dispatch(): void
    {
        $chatId = $this->telegram->ChatID();
        if ($chatId === null || $chatId === '') {
            // Ignore updates that don't carry a chat context (e.g., my_chat_member)
            return;
        }

        $text = trim((string) $this->telegram->Text());
        $updateType = $this->telegram->getUpdateType();

        $this->userAddress->ensureUserExists(
            $chatId,
            (string) ($this->telegram->FirstName() ?? ''),
            (string) ($this->telegram->LastName() ?? '')
        );

        if ($text !== '' && strpos($text, '/start') === 0) {
            $this->handleStart($chatId, $text);
        }

        if ($text !== '' && strpos($text, '/help') === 0) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "کافیست ربات را با کلیک روی این لینک شروع کنید و سپس /start را بزنید\n                    https://t.me/mazandbarghalertbot\nهمچنین شما میتونید همزمان چند آدرس را در ربات ثبت کنید تا همزمان قطعی منزل/محل کار را داشته باشید.",
            ]);
        }

        if ($updateType === TelegramService::MESSAGE && $text !== '') {
            $this->handleMessageText($chatId, $text);
        }

        if ($updateType === TelegramService::CONTACT) {
            $this->handleContact($chatId);
        }

        if ($this->telegram->getUpdateType() === TelegramService::CALLBACK_QUERY) {
            $this->handleCallback($chatId, $text);
        }  
        
    }

    protected function handleStart(int|string $chatId, string $text): void
    {
        $user = $this->userAddress->findUserByChatId($chatId);
        $payload = '';
        if (preg_match('/^\/start\s+(.+)$/', $text, $m)) {
            $payload = trim((string) $m[1]);
        }
        $buttons = [
            [
                $this->telegram->buildInlineKeyboardButton('🔌 🔔 فعـــــالســـــازی ربــــــات', '', 'TURN_ON_BOT'),
            ],
            [
                $this->telegram->buildInlineKeyboardButton('آموزش استفاده', '', 'HELP'),
            ],
        ];
        $replyMarkup = $this->telegram->buildInlineKeyBoard($buttons);
        $note = '<blockquote>⚠️ سلب مسئولیت:  اطلاعات این ربات بر اساس داده‌های رسمی شرکت توزیع نیروی برق مازندران (maztozi.ir) است و هیچ داده غیررسمی وجود ندارد. به دلیل اختلالات شبکه، داده‌ها ممکن است کامل نباشند.</blockquote>';
        $message = "👋 سلام! خوش اومدی به ربات اطلاع رسانی قطعی برق مازندران!\n"
            . "توجه‌داشته‌باشید ربات هیچ ارتباطی با اداره برق ندارد و تنها\n"
            . "جهت خدمت‌رسانی به همشهریان عزیز ایجاد شده‌است.\n\n"
            . $note . "\n\n";
            
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            // 'reply_markup' => $replyMarkup,
        ]);

        if ($payload !== '' && str_starts_with($payload, 'add-')) {
            $addressId = (int) str_replace('add-', '', $payload);
            //$addressId = Address::where('code', $code)->value('id');
           
            if ($addressId) {
                $this->confirmAddressAdded($chatId, (int) $addressId);
                $this->menu->hideReplyKeyboard($chatId);
                $this->menu->sendMainMenu($chatId);
            } else {
                $this->menu->sendMainMenu($chatId);
            }
        } else {
            $this->menu->sendMainMenu($chatId);
        }
    }

    protected function handleMessageText(int|string $chatId, string $text): void
    {
        $state = $this->state->get($chatId);

        if (array_key_exists('step', $state) && $state['step'] === 'await_rename' && array_key_exists('address_id', $state)) {
            if ($text === 'انصراف') {
                $this->state->clear($chatId);
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ ویرایش برچسب لغو شد.',
                ]);
                $this->menu->hideReplyKeyboard($chatId);
                $this->showAddressList($chatId);
                $this->menu->sendMainMenu($chatId);
                return;
            }

            $this->userAddress->setAddressAlias($chatId, (int) $state['address_id'], $text);
            $this->state->clear($chatId);
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '✅برچسب ذخیره شد.',
            ]);
            $this->menu->hideReplyKeyboard($chatId);
            $this->showAddressList($chatId);
            $this->menu->sendMainMenu($chatId);
            return;
        }

        if (array_key_exists('step', $state) && $state['step'] === 'await_keyword' && array_key_exists('city_id', $state)) {
            // During keyword step, ignore main menu reply buttons and re-prompt
            $mainMenuButtons = [
                '🗂️ آدرس‌های من',
                '📍️ افزودن آدرس جدید',
                '🔴 وضعیت قطعی‌ها',
                '💡 درباره ما',
                '📨 پیشنهاد یا گزارش مشکل',
                '📜 قوانین و مقررات',
            ];
            if (in_array($text, $mainMenuButtons, true)) {
                $this->addressFlow->promptForKeyword($chatId, (int) $state['city_id']);
                return;
            }

            $this->addressFlow->handleKeywordSearch($chatId, (int) $state['city_id'], $text);
            return;
        }

        // Handle feedback flow
        if (array_key_exists('step', $state) && $state['step'] === 'await_feedback') {
            if ($text === 'انصراف') {
                $this->state->clear($chatId);
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ ارسال پیشنهاد/گزارش لغو شد.',
                ]);
                $this->menu->hideReplyKeyboard($chatId);
                $this->menu->sendMainMenu($chatId);
                return;
            }

            $user = $this->userAddress->findUserByChatId($chatId);
            $firstName = $user ? (string) ($user->first_name ?? '') : (string) ($this->telegram->FirstName() ?? '');
            $lastName = $user ? (string) ($user->last_name ?? '') : (string) ($this->telegram->LastName() ?? '');
            $username = (string) ($this->telegram->Username() ?? '');
            $mobile = $user ? (string) ($user->mobile ?? '') : '-';

            $name = trim(($firstName . ' ' . $lastName)) ?: '-';
            $usernameLine = $username !== '' ? '@' . $username : '-';

            $adminChatId = (string) config('services.telegram.admin_chat_id', '');
            if ($adminChatId !== '') {
                $adminMessage = "📬 پیام جدید از کاربر\n\n"
                    . '👤 نام: ' . $name . "\n"
                    . '🆔 ChatID: ' . $chatId . "\n"
                    . '🏷️ Username: ' . $usernameLine . "\n"
                    . '📱 موبایل: ' . $mobile . "\n\n"
                    . "متن:\n" . $text;

                $this->telegram->sendMessage([
                    'chat_id' => $adminChatId,
                    'text' => $adminMessage,
                ]);
            }

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '✅ پیام شما برای مدیر ارسال شد. ممنون از همراهی‌تون!',
            ]);
            $this->state->clear($chatId);
            $this->menu->hideReplyKeyboard($chatId);
            $this->menu->sendMainMenu($chatId);
            return;
        }

        if ($text === '📍️ افزودن آدرس جدید') {
            $this->addressFlow->showAddAddressFlow($chatId);
        } elseif ($text === '🗂️ آدرس‌های من') {
            $this->showAddressList($chatId);
        } elseif ($text === '💡 درباره ما') {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '👨‍💻درباره‌ی ما:
تو این شرایط سخت ندونستن زمان قطعی برق باعث شده خیلی از کسب و کار ها، جلسات، برنامه ریزی ها و قرار های کاری به هم بریزه. خب ما کاری از دستمون در مورد قطعی برق بر نمیاد ولی حداقل تلاش کردیم خدمت کوچیکی به هم استانی های عزیز کرده باشیم.',
                'parse_mode' => 'HTML',
            ]);
        } elseif ($text === '📨 پیشنهاد یا گزارش مشکل') {
            $this->state->set($chatId, ['step' => 'await_feedback']);
            $keyboard = [
                [
                    $this->telegram->buildKeyboardButton('انصراف'),
                ],
            ];
            $replyKeyboard = $this->telegram->buildKeyBoard($keyboard, true, true, true);
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'ممنون از همراهیتون! 😊 لطفاً پیشنهاد یا گزارش مشکلی که دارید رو تو یه پیام بفرستید. همه پیام‌ها با دقت توسط مدیر بررسی می‌شن! 🌟',
                'reply_markup' => $replyKeyboard,
            ]);
        } elseif ($text === '📜 قوانین و مقررات') {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'در حال حاضر قوانین و مقررات وجود ندارد',
            ]);
        } elseif ($text === '🔴 وضعیت قطعی‌ها') {
            $this->notifyTodaysBlackoutsForAllAddresses($chatId);
        }
    }

    protected function handleContact(int|string $chatId): void
    {
        $raw = (string) $this->telegram->getContactPhoneNumber();
        $normalized = $this->phone->normalizeIranMobile($raw);
        if ($normalized === null) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ لطفاً شماره تلفن ایران معتبر ارسال کنید (با کد +98).',
            ]);
            return;
        }

        $user = $this->userAddress->findUserByChatId($chatId);
        if ($user) {
            $user->mobile = $normalized;
            $user->is_verified = true;
            $user->save();
        }

        $state = $this->state->get($chatId);
        if (array_key_exists('pending_add_code', $state)) {
            $code = (int) $state['pending_add_code'];
            $pendingAddressId = Address::where('code', $code)->value('id');
            $this->state->clear($chatId);
            if ($pendingAddressId) {
                $this->confirmAddressAdded($chatId, (int) $pendingAddressId);
                $this->menu->hideReplyKeyboard($chatId);
                $this->menu->sendMainMenu($chatId);
                return;
            }
        }

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '✅ شماره موبایل شما تایید شد. حالا می‌توانید آدرس اضافه کنید.',
        ]);
        $this->menu->hideReplyKeyboard($chatId);
        $this->menu->sendMainMenu($chatId);
    }

    protected function handleCallback(int|string $chatId, string $text): void
    {
        if ($text === 'HELP') {
            $message = "کافیست ربات را با کلیک روی این لینک شروع کنید و سپس /start را بزنید\nhttps://t.me/mazandbarghalertbot\nهمچنین شما میتونید همزمان چند آدرس را در ربات ثبت کنید تا همزمان قطعی منزل/محل کار را داشته باشید.";
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $this->telegram->Callback_ID(),
            ]);
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
            ]);
        }
        if ($text === 'ADD_ADDR') {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $this->telegram->Callback_ID(),
            ]);
            $this->addressFlow->showAddAddressFlow($chatId);
        }
        if ($text === 'VIEW_ADDRS') {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $this->telegram->Callback_ID(),
            ]);
            $this->showAddressList($chatId);
        }
        if (strpos($text, 'CITY_') === 0) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $this->telegram->Callback_ID(),
            ]);
            $cityId = (int) str_replace('CITY_', '', $text);
            $this->addressFlow->promptForKeyword($chatId, $cityId);
        }
        if ($text === 'SEARCH_AGAIN') {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $this->telegram->Callback_ID(),
            ]);
            $state = $this->state->get($chatId);
            $cityId = (int) ($state['city_id'] ?? 0);
            if ($cityId > 0) {
                $this->addressFlow->promptForKeyword($chatId, $cityId);
            } else {
                $this->addressFlow->showAddAddressFlow($chatId);
            }
        }
        if ($text === 'BACK_TO_ADD') {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $this->telegram->Callback_ID(),
            ]);
            $this->addressFlow->showAddAddressFlow($chatId);
        }
        if ($text === 'BACK_TO_MENU') {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $this->telegram->Callback_ID(),
            ]);
            $this->state->clear($chatId);
            $this->menu->sendMainMenu($chatId);
        }
        if (strpos($text, 'ADDR_') === 0) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $this->telegram->Callback_ID(),
            ]);
            $addressId = (int) str_replace('ADDR_', '', $text);
            $this->confirmAddressAdded($chatId, $addressId);
        }
        if (strpos($text, 'DEL_') === 0) {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $this->telegram->Callback_ID(),
            ]);
            $addressId = (int) str_replace('DEL_', '', $text);
            $this->userAddress->removeUserAddress($chatId, $addressId);
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '🗑️ آدرس حذف شد.',
            ]);
            //$this->showAddressList($chatId);
        }
        if (strpos($text, 'TOGGLE_') === 0) {
            $addressId = (int) str_replace('TOGGLE_', '', $text);
            $this->userAddress->toggleAddressNotify($chatId, $addressId);
            // Update the same message (text + inline keyboard) instead of sending a new one
            $messageId = $this->telegram->MessageID();
            [$newText, $newReplyMarkup, $isActive] = $this->buildAddressCardForUser($chatId, $addressId);
            if ($newText !== null) {
                $payload = [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => $newText,
                    'parse_mode' => 'HTML',
                ];
                if ($newReplyMarkup !== null) {
                    $payload['reply_markup'] = $newReplyMarkup;
                }
                $this->telegram->editMessageText($payload);
            }
            // Show a brief toast to confirm status update
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $this->telegram->Callback_ID(),
                'text' => 'وضعیت اعلان به‌روزرسانی شد: ' . ($isActive ? 'روشن' : 'خاموش'),
                'show_alert' => false,
            ]);
        }
        if (strpos($text, 'RENAME_') === 0) {
            $keyboard = [
                [
                    $this->telegram->buildKeyboardButton('انصراف'),
                ],
            ];
            $replyKeyboard = $this->telegram->buildKeyBoard($keyboard, true, true, true);
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $this->telegram->Callback_ID(),
            ]);
            $addressId = (int) str_replace('RENAME_', '', $text);
            $this->state->set($chatId, ['step' => 'await_rename', 'address_id' => $addressId]);
            
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '✏️ یک اسم برای این آدرس بنویس',
                'reply_markup' => $replyKeyboard,
            ]);
        }
        // if (strpos($text, 'EDIT_') === 0) {
        //     $this->telegram->answerCallbackQuery([
        //         'callback_query_id' => $this->telegram->Callback_ID(),
        //     ]);
        //     $addressId = (int) str_replace('EDIT_', '', $text);
        //     $address = Address::find($addressId);
        //     if ($address) {
        //         $this->addressFlow->promptForKeyword($chatId, (int) $address->city_id);
        //     } else {
        //         $this->addressFlow->showAddAddressFlow($chatId);
        //     }
        // }
        if (strpos($text, 'SHARE_') === 0) {
            $botUsername = config('services.telegram.bot_username');

            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $this->telegram->Callback_ID(),
            ]);
            $addressId = (int) str_replace('SHARE_', '', $text);
            $link = 'https://t.me/' . $botUsername . '?start=add-' . $addressId;

            $address = Address::with('city')->find($addressId);
            $cityName = $address && $address->city ? (string) $address->city->name() : '';
            $addressText = $address ? (string) ($address->address ?? '') : '';
            $locationLine = '📍 ' . trim(($cityName !== '' ? $cityName . ' | ' : '') . $addressText, ' |');

            $cta = "سلام! 😊\n"
                . "اگه عضو ربات بشی، هر روز صبح ساعت 7 و حدود 20 دقیقه قبل از هر قطعی برق بهت خبر میده.\n"
                . "این آدرس هم همون لحظه برات اضافه می‌شه و از این به بعد اعلان می‌گیری:\n"
                . '<blockquote>' . e($locationLine) . '</blockquote>' . "\n"
                . "برای شروع، روی این لینک بزن:\n"
                . $link;

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'پیام زیر رو برای شخصی که میخواد اضافه کنه ارسال کن' . "\n\n" . "👇👇👇",
            ]);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $cta,
                'parse_mode' => 'HTML',
            ]);
        }
        if ($text === 'TURN_ON_BOT') {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $this->telegram->Callback_ID(),
            ]);
            $this->menu->sendMainMenu($chatId);
        }
        if ($text === 'TURN_OFF_BOT') {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $this->telegram->Callback_ID(),
            ]);
        }
    }

    protected function showAddressList(int|string $chatId): void
    {
        $user = $this->userAddress->findUserByChatId($chatId);
        $addresses = $user ? $user->addresses()->with('city')->get() : collect();


        if ($addresses->count() === 0) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '📭 هنوز آدرسی اضافه نکرده‌اید.',
            ]);
        }

        foreach ($addresses as $address) {
            [$text, $replyMarkup] = $this->buildAddressCardForUser($chatId, (int) $address->id);
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $text ?? '',
                'parse_mode' => 'HTML',
                'reply_markup' => $replyMarkup,
            ]);
        }

        // $this->telegram->sendMessage([
        //     'chat_id' => $chatId,
        //     'text' => 'بازگشت به منو:',
        //     'reply_markup' => $this->telegram->buildInlineKeyBoard([
        //         [
        //             $this->telegram->buildInlineKeyboardButton('بازگشت', '', 'BACK_TO_MENU'),
        //         ],
        //     ]),
        // ]);
    }

    /**
     * Build a single address card (text + inline keyboard) for a given user/address.
     *
     * @return array{0:?string,1:?string} [text, replyMarkup]
     */
    protected function buildAddressCardForUser(int|string $chatId, int $addressId): array
    {
        $user = $this->userAddress->findUserByChatId($chatId);
        if (!$user) {
            return [null, null, null];
        }

        $address = $user->addresses()->with('city')->where('addresses.id', $addressId)->first();
        if (!$address) {
            return [null, null, null];
        }

        $alias = $address->pivot->name ?? null;
        $cityName = $address->city ? '📍 ' . $address->city->name() : '';
        $titleLine = $alias ? '📌 نام محل: ' . $alias . "\n" : '';
        $active = (bool) ($address->pivot->is_active ?? true);
        $status = $active ? '<blockquote>🔔 اعلان: روشن</blockquote>' : '<blockquote>🔕 اعلان: خاموش</blockquote>';
        $text = $titleLine . $cityName . ' | ' . $address->address . "\n\n" . $status;

        $buttons = [
            [
                $this->telegram->buildInlineKeyboardButton('حذف 🗑️', '', 'DEL_' . $address->id),
                $this->telegram->buildInlineKeyboardButton('برچسب ✏️', '', 'RENAME_' . $address->id),
            ],
            [
                $this->telegram->buildInlineKeyboardButton($active ? 'خاموش کردن اعلان 🔕' : 'روشن کردن اعلان 🔔', '', 'TOGGLE_' . $address->id),
                $this->telegram->buildInlineKeyboardButton('اشتراک‌گذاری 🔗', '', 'SHARE_' . $address->id),
            ],
        ];

        return [$text, $this->telegram->buildInlineKeyBoard($buttons), $active];
    }

    protected function confirmAddressAdded(int|string $chatId, int $addressId): void
    {
        $address = Address::with('city')->find($addressId);
        [$msg] = $this->userAddress->confirmAddressAddedMessageParts($address);

        $buttons = [
            [
                $this->telegram->buildInlineKeyboardButton('افزودن آدرس جدید', '', 'ADD_ADDR'),
            ],
            [
                $this->telegram->buildInlineKeyboardButton('مشاهده لیست آدرس ها', '', 'VIEW_ADDRS'),
            ],
            [
                $this->telegram->buildInlineKeyboardButton('بازگشت', '', 'BACK_TO_MENU'),
            ],
        ];
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $msg,
            'reply_markup' => $this->telegram->buildInlineKeyBoard($buttons),
        ]);
        $this->state->clear($chatId);

        if ($address) {
            $this->userAddress->attachUserAddress($chatId, $addressId);
            $this->notifyTodaysBlackouts($chatId, $addressId);
        }
    }

    protected function notifyTodaysBlackouts(int|string $chatId, int $addressId): void
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

        $lines = [];
        foreach ($blackouts as $index => $b) {
            $start = $b->outage_start_time ? Carbon::parse($b->outage_start_time)->format('H:i') : '—';
            $end = $b->outage_end_time ? Carbon::parse($b->outage_end_time)->format('H:i') : '—';
            $num = $index + 1;
            $lines[] = $num . '. ' . 'ساعت ' . $start . ' الی ' . $end;
        }

        $cityName = '';
        $address = Address::with('city')->find($addressId);
        if ($address && $address->city) {
            $cityName = (string) $address->city->name();
        }
        $locationLine = '📍 ' . trim(($cityName !== '' ? $cityName . ' | ' : '') . ($address->address ?? ''), ' |');

        $sections = [];
        foreach ($blackouts as $b) {
            $start = $b->outage_start_time ? Carbon::parse($b->outage_start_time)->format('H:i') : '—';
            $end = $b->outage_end_time ? Carbon::parse($b->outage_end_time)->format('H:i') : '—';
            $sections[] = '<blockquote>' . e('⏰ ' . $dateFa . ' ساعت ' . $start . ' الی ' . $end) . '</blockquote>';
        }

        $final = '📅 برنامه قطعی امروز (' . $dateFa . '):' . "\n\n"
            . e($locationLine) . "\n\n"
            . implode("\n\n", $sections);

        // Always send as a NEW message to keep previous search results visible
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $final,
            'parse_mode' => 'HTML',
        ]);
    }

    protected function notifyTodaysBlackoutsForAllAddresses(int|string $chatId): void
    {
        $user = $this->userAddress->findUserByChatId($chatId);
        $addresses = $user ? $user->addresses()->with('city')->get() : collect();

        if ($addresses->isEmpty()) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '📭 هنوز آدرسی اضافه نکرده‌اید.',
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

            $cityName = $address->city ? $address->city->name() : '';
            $locationLine = '📍 ' . trim(($cityName !== '' ? $cityName . ' | ' : '') . $address->address, ' |');

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
}


