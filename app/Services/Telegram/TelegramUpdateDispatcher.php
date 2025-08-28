<?php
declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Address;
use App\Models\User;
use App\Services\Billing\SubscriptionBillingService;
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
        public SubscriptionBillingService $billing,
        public SmsSubscriptionFlowService $smsFlow,
        public BroadcastService $broadcast,
        public FeedbackService $feedback,
        public BlackoutNotificationService $blackouts,
        public AddressCardBuilder $addressCard,
    ) {
    }

    /**
     * Send not allowed message and fallback to main menu.
     */
    protected function denyAdminAndReturn(int|string $chatId): void
    {
        $this->state->clear($chatId);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '⛔️ این دستور برای شما مجاز نیست',
        ]);
        $this->menu->sendMainMenu($chatId);
    }

    /**
     * Centralized purchase cancel UX.
     */
    protected function cancelPurchase(int|string $chatId): void
    {
        $this->state->clear($chatId);
        $this->menu->hideReplyKeyboard($chatId);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'خرید اشتراک پیامکی لغو شد. برای شروع دوباره، «💬 دریافت هشدار با SMS» را انتخاب کنید.',
        ]);
        $this->menu->sendMainMenu($chatId);
    }

    /**
     * Enter SMS naming flow if there are uncovered addresses without names; otherwise go invoice.
     */
    protected function proceedAfterSmsConsent(int|string $chatId): void
    {
        $this->smsFlow->proceedAfterConsent($chatId);
    }

    /**
     * Show the SMS naming wizard for addresses missing a user-defined name.
     */
    protected function showSmsNamingWizard(int|string $chatId, $needsNames): void
    {
        // Deprecated in simplified linear flow (kept for backward compatibility if called directly)
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'برای ارسال پیامک کوتاه، باید برای آدرس‌ها نام کوتاه تعیین شود. لطفاً دستور را دوباره اجرا کنید.',
        ]);
    }

    protected function promptNextSmsName(int|string $chatId): void
    {
        $this->smsFlow->promptNextSmsName($chatId);
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
            return;
        }

        if ($text !== '' && strpos($text, '/help') === 0) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "کافیست ربات را با کلیک روی این لینک شروع کنید و سپس /start را بزنید\n                    https://t.me/mazandbarghalertbot\nهمچنین شما میتونید همزمان چند آدرس را در ربات ثبت کنید تا همزمان قطعی منزل/محل کار را داشته باشید.",
            ]);
            return;
        }

        if ($updateType === TelegramService::MESSAGE && $text !== '') {
            $this->handleMessageText($chatId, $text);
        }

        if ($updateType === TelegramService::CONTACT) {
            $this->handleContact($chatId);
        }

        if ($this->telegram->getUpdateType() === TelegramService::CALLBACK_QUERY) {
            Log::info('Callback Query', ['chat_id' => $chatId, 'text' => $text]);   
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

        // Global cancel for purchase after invoice (reply keyboard)
        if ($text === 'انصراف از خرید') {
            $this->smsFlow->cancelPurchase($chatId);
            return;
        }

        // Handle broadcast confirm step (admin) - allow cancel via reply button
        if (array_key_exists('step', $state) && $state['step'] === 'await_broadcast_confirm') {
            if ($text === 'انصراف') {
                $this->state->clear($chatId);
                $this->menu->hideReplyKeyboard($chatId);
                $this->menu->sendAdminMenu($chatId);
                return;
            }
        }

        // Handle broadcast text input (admin only)
        if (array_key_exists('step', $state) && $state['step'] === 'await_broadcast_text') {
            if (!$this->broadcast->isAdmin($chatId)) {
                $this->denyAdminAndReturn($chatId);
                return;
            }
            $this->broadcast->handleText($chatId, $text);
            return;
        }

        if (array_key_exists('step', $state) && $state['step'] === 'await_rename' && array_key_exists('address_id', $state)) {
            if ($text === 'انصراف') {
                $this->state->clear($chatId);
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ ویرایش برچسب لغو شد.',
                ]);
                $this->menu->sendMainMenu($chatId);
                $this->menu->hideReplyKeyboard($chatId);
                $this->showAddressList($chatId);
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

        // Linear SMS naming flow
        if (array_key_exists('step', $state) && $state['step'] === 'sms_name_flow' && array_key_exists('queue', $state) && array_key_exists('pos', $state)) {
            $this->smsFlow->handleNameFlowText($chatId, $state, $text);
            return;
        }

        if (array_key_exists('step', $state) && $state['step'] === 'await_keyword' && array_key_exists('city_id', $state)) {
            // During keyword step, ignore main menu reply buttons and re-prompt
            $mainMenuButtons = [
                '💬 دریافت هشدار با SMS',
                '🗂️ آدرس‌های من',
                '📍️ افزودن آدرس جدید',
                '🔴 قطعی‌های امروز',
                '📆 قطعی‌های فردا',
                '💡 درباره ما',
                '📨 پیشنهاد یا گزارش مشکل',
                '📜 قوانین و مقررات',
                '👤 مدیریت ربات',
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
                $this->feedback->cancel($chatId);
                return;
            }
            $this->feedback->handle($chatId, $text);
            return;
        }

        if ($text === '📍️ افزودن آدرس جدید' || $text === '/add_new_address') {
            $this->addressFlow->showAddAddressFlow($chatId);
        } elseif ($text === '🗂️ آدرس‌های من' || $text === '/my_addresses') {
            $this->showAddressList($chatId);
        } elseif ($text === '💡 درباره ما' || $text === '/about_us') {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '👨‍💻درباره‌ی ما:
تو این شرایط سخت ندونستن زمان قطعی برق باعث شده خیلی از کسب و کار ها، جلسات، برنامه ریزی ها و قرار های کاری به هم بریزه. خب ما کاری از دستمون در مورد قطعی برق برنمیاد ولی حداقل تلاش کردیم خدمت کوچیکی به هم استانی های عزیز کرده باشیم.',
                'parse_mode' => 'HTML',
            ]);
        } elseif ($text === '📨 پیشنهاد یا گزارش مشکل' || $text === '/feedback') {
            $this->feedback->start($chatId);
        } elseif ($text === '👤 مدیریت ربات') {
            if ($this->menu->isAdmin($chatId)) {
                $this->menu->sendAdminMenu($chatId);
            } else {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '⛔️ این دستور برای شما مجاز نیست',
                ]);
                $this->menu->sendMainMenu($chatId);
            }
        } elseif ($text === '▶️ پیام همگانی') {
            if (!$this->broadcast->isAdmin($chatId)) {
                $this->denyAdminAndReturn($chatId);
                return;
            }
            $this->broadcast->startCompose($chatId);
            return;
        } elseif ($text === '🙍‍♂️ آمار کاربران') {
            if (!$this->broadcast->isAdmin($chatId)) {
                $this->denyAdminAndReturn($chatId);
                return;
            }

            $totalUsers = (int) User::query()->count();
            $activeUsers = (int) User::query()->where('is_active', true)->count();

            $msg = '👥 آمار کاربران' . "\n\n"
                . '🔢 مجموع کاربران: ' . number_format($totalUsers) . "\n"
                . '✅ کاربران فعال: ' . number_format($activeUsers);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $msg,
            ]);
            $this->menu->sendAdminMenu($chatId);
        } elseif ($text === '↩️ بازگشت به منو اصلی') {
            $this->menu->sendMainMenu($chatId);
        } elseif ($text === '💬 دریافت هشدار با SMS' || $text === '/sms_alert') {
            $this->smsFlow->beginPurchase($chatId);
        } elseif ($text === '📜 قوانین و مقررات') {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'در حال حاضر قوانین و مقررات وجود ندارد',
            ]);
        } elseif ($text === '🔴 قطعی‌های امروز') {
            $this->blackouts->notifyTodayForAllAddresses($chatId);
        } elseif ($text === '📆 قطعی‌های فردا') {    
            $this->blackouts->notifyTomorrowForAllAddresses($chatId);
        } elseif ($text === '🔴 وضعیت قطعی‌ها' || $text === 'آپدیت ها') {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "🎉 بروزرسانی جدید!\n\nکاربران گرامی، ربات مازندبرق به نسخه جدید بروزرسانی شد. \nبرای دریافت بروزرسانی جدید و استفاده از ربات، لطفاً دستور 👈 /start  👉 را مجددا اجرا نمایید.\n\nویژگی ها جدید:\n<blockquote>✅ دریافت برنامه قطعی روز آینده</blockquote>\nرفع باگ:\n<blockquote>✅ جستجو با حروف عربی</blockquote>\n<blockquote>✅ رفع برخی باگ‌ها</blockquote>\nبهبودها:\n<blockquote>✅ کوتاه تر شدن انتخاب آدرس</blockquote>\n\n💠 تیم پشتیبانی ربات مازندبرق",
                'parse_mode' => 'HTML',
            ]);
        }else{
            $text = 'عزیزم دستوری که فرستادی ربات نمیفهمه. ' ."\n".'باید از دکمه‌های پایین استفاده کنی 😉' . "\n\n" . '👇👇👇';
            $this->menu->sendMainMenuWithMessage($chatId, $text);
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
        // Broadcast confirmation
        if ($text === 'BROADCAST_CONFIRM') {
            if (!$this->broadcast->isAdmin($chatId)) {
                return;
            }
            $this->broadcast->confirmAndDispatch($chatId);
            return;
        }
        if ($text === 'BROADCAST_EDIT') {
            if (!$this->broadcast->isAdmin($chatId)) {
                return;
            }
            $this->broadcast->edit($chatId);
            return;
        }
        if ($text === 'BROADCAST_ABORT') {
            if (!$this->broadcast->isAdmin($chatId)) {
                return;
            }
            $this->broadcast->abort($chatId);
            return;
        }
        if ($text === 'CANCEL_BROADCAST') {
            if (!$this->broadcast->isAdmin($chatId)) {
                return;
            }
            $this->broadcast->cancelActive($chatId);
            return;
        }
        if ($text === 'SMS_TERMS_OK') {
            $this->smsFlow->proceedAfterConsent($chatId);
            return;
        }
        if ($text === 'SMS_NAME_ABORT') {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $this->telegram->Callback_ID(),
            ]);
            $this->state->clear($chatId);
            $this->menu->hideReplyKeyboard($chatId);
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ نام گذاری آدرس لغو شد.' . "\n\n" . 'شما از فرایند خرید اشتراک خارج شدید.',
            ]);
            $this->menu->sendMainMenu($chatId);
            return;
        }
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
        // Handle cancel from inline button at invoice stage
        if ($text === 'SMS_CANCEL') {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $this->telegram->Callback_ID(),
            ]);
            $this->smsFlow->cancelPurchase($chatId);
            return;
        }
        // Removed legacy SMS_CONTINUE (no longer used)
        if ($text === 'SMS_CONTINUE') {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $this->telegram->Callback_ID(),
            ]);
            $user = $this->userAddress->findUserByChatId($chatId);
            $uncovered = $user ? $this->billing->getUncoveredAddressIds($user) : [];
            if (empty($uncovered)) {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'فعلاً آدرسی برای خرید اشتراک وجود ندارد.',
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
                $this->smsFlow->promptNextSmsName($chatId);
                return;
            }

            $this->smsFlow->sendConsent($chatId);
        }
        // Removed legacy RENAME_SMS_ flow
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
            $cityName = (string) (($address && $address->city) ? ($address->city->name ?? '') : '');
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
               'text' => '📭 هنوز آدرسی اضافه نکرده‌اید.' . "\n\n" . 'برای اضافه کردن آدرس، بر روی 👈  /add_new_address  👉 بزنید' . "\n\n" . 'یا بر روی دکمه پایین 📍افزودن آدرس جدید بزنید:' . "\n\n" . '👇👇👇',
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
        return $this->addressCard->buildForUser($chatId, $addressId);
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
            $this->blackouts->notifyTodays($chatId, $addressId);
        }
    }

    protected function notifyTodaysBlackouts(int|string $chatId, int $addressId): void
    {
        $this->blackouts->notifyTodays($chatId, $addressId);
    }

    protected function notifyTodaysBlackoutsForAllAddresses(int|string $chatId): void
    {
        $this->blackouts->notifyTodayForAllAddresses($chatId);
    }

    protected function notifyTomorrowBlackoutsForAllAddresses(int|string $chatId): void
    {
        $this->blackouts->notifyTomorrowForAllAddresses($chatId);
    }

    protected function sendSmsInvoicePreview(int|string $chatId, $user, array $uncovered): void
    {
        $this->smsFlow->sendInvoicePreview($chatId, $user, $uncovered);
    }
    
    /**
     * Render broadcast progress report message.
     *
     * @param array{total:int,processing:int,processed:int,success:int,failed:int,remaining:int} $stats
     */
    protected function renderBroadcastReport(array $stats): string
    {
        $total = (int) ($stats['total'] ?? 0);
        $processing = (int) ($stats['processing'] ?? 0);
        $processed = (int) ($stats['processed'] ?? 0);
        $success = (int) ($stats['success'] ?? 0);
        $failed = (int) ($stats['failed'] ?? 0);
        $remaining = (int) ($stats['remaining'] ?? max(0, $total - $processed));

        $lines = [];
        $lines[] = '📣 گزارش ارسال همگانی';
        $lines[] = '';
        $lines[] = '👥 کل ارسال: ' . number_format($total) . ' کاربر';
        $lines[] = '⏳ در حال ارسال: ' . number_format($processing) . ' کاربر';
        $lines[] = '📤 ارسال شده: ' . number_format($processed);
        $lines[] = '✅ موفق: ' . number_format($success);
        $lines[] = '❌ ناموفق: ' . number_format($failed);
        $lines[] = '🧮 باقی‌مانده: ' . number_format($remaining);

        return implode("\n", $lines);
    }
}


