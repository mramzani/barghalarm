<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\SubscriptionBillingService;
use App\Services\Payment\Gateway\Zarinpal;
use App\Services\Telegram\TelegramService;
use App\Services\Telegram\PhoneNumberNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Hekmatinasser\Verta\Verta;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    public function __construct(
        public SubscriptionBillingService $billing,
        public TelegramService $telegram,
        public Zarinpal $gateway,
        public PhoneNumberNormalizer $phone
    ) {
    }

    public function invoice(Request $request): Response
    {
        $chatId = (string) $request->query('chat_id', '');
        $user = User::where('chat_id', $chatId)->firstOrFail();
        // Determine uncovered addresses without active subscription coverage
        $uncovered = $this->billing->getUncoveredAddressIds($user);
        $addressCount = count($uncovered);

        $pricePer = SubscriptionBillingService::PRICE_PER_ADDRESS;
        $monthly = $addressCount * $pricePer;
        $maxEnd = $user->subscriptions()->where('status', 'active')->max('ends_on');
        $endsFa = $maxEnd ? (new Verta(Carbon::parse($maxEnd)))->format('Y/m/d') : null;

        return response()->view('payments.checkout', [
            'chatId' => $chatId,
            'user' => $user,
            'uncoveredCount' => $addressCount,
            'pricePer' => $pricePer,
            'monthly' => $monthly,
            'endsFa' => $endsFa,
        ])->setPrivate();
    }

    public function invoicePost(Request $request): Response
    {
        $chatId = (string) $request->input('chat_id', '');
        $rawMobile = (string) $request->input('mobile', '');
        $user = User::where('chat_id', $chatId)->firstOrFail();

        $normalized = $this->phone->normalizeIranMobile($rawMobile);
        if ($normalized === null) {
            return back()->withInput()->withErrors(['mobile' => 'شماره موبایل معتبر نیست.']);
        }
        // Save mobile without OTP
        $user->mobile = $normalized;
        $user->is_verified = true;
        $user->save();

        // Recompute uncovered addresses to ensure integrity
        $uncovered = $this->billing->getUncoveredAddressIds($user);
        $addressCount = count($uncovered);
        if ($addressCount === 0) {
            $maxEnd = $user->subscriptions()->where('status', 'active')->max('ends_on');
            $endsFa = $maxEnd ? (new Verta(Carbon::parse($maxEnd)))->format('Y/m/d') : null;
            return response()->view('payments.checkout', [
                'chatId' => $chatId,
                'user' => $user,
                'uncoveredCount' => 0,
                'pricePer' => SubscriptionBillingService::PRICE_PER_ADDRESS,
                'monthly' => 0,
                'endsFa' => $endsFa,
            ]);
        }

        $payment = $this->billing->getOrCreatePendingPayment($user, $addressCount, $chatId, $uncovered);

        $response = (array) $this->gateway->request($payment);
        $data = (array) ($response['data'] ?? []);
        $authority = (string) ($data['authority'] ?? '');
        if ($authority === '') {
            $payment->status = 'failed';
            $payment->save();
            return back()->withErrors(['gateway' => 'مشکل در اتصال به درگاه پرداخت.']);
        }
        $payment->authority = $authority;
        $payment->save();

        $sandbox = (bool) config('services.zarinpal.sandbox');
        $startBase = $sandbox ? 'https://sandbox.zarinpal.com/pg/StartPay/' : 'https://www.zarinpal.com/pg/StartPay/';
        return redirect()->away($startBase . $authority);
    }

    public function callback(Request $request)
    {
        $paymentId = (int) $request->query('payment_id', 0);
        $payment = Payment::findOrFail($paymentId);

        // Idempotent: if already paid, just render success view without duplicating
        if ($payment->status === 'paid') {
            return $this->renderResultView(true, $payment);
        }

        $verify = (array) $this->gateway->verify($request, $payment);
        $data = (array) ($verify['data'] ?? []);
        $code = (int) ($data['code'] ?? 0);
        $refId = $data['ref_id'] ?? ($data['refID'] ?? null);

        if ($code === 100 || $code === 101) {
            $payment->ref_number = (string) $refId;
            $this->billing->markPaymentPaid($payment);
            $user = User::findOrFail($payment->user_id);
            // Prevent duplicate subscription on refresh
            $existingActiveSub = $user->subscriptions()->where('status', 'active')->latest('id')->first();
            if (!$existingActiveSub || (string) $existingActiveSub->payment_id !== (string) $payment->id) {
                $this->billing->provisionMonthlySubscription($user, $payment);
            }

            if (!empty($payment->chat_id)) {
                $this->telegram->sendMessage([
                    'chat_id' => $payment->chat_id,
                    'text' => $this->buildSuccessMessageForPayment($payment),
                    'parse_mode' => 'Markdown',
                ]);
            }
            return $this->renderResultView(true, $payment);
        }

        $payment->status = 'failed';
        $payment->save();

        if (!empty($payment->chat_id)) {
            $this->telegram->sendMessage([
                'chat_id' => $payment->chat_id,
                'text' => $this->buildFailureMessage(),
                'parse_mode' => 'Markdown',
            ]);
        }

        return $this->renderResultView(false, $payment);
    }

    /**
     * Build a clean, user-friendly success message in Markdown.
     */
    protected function buildSuccessMessage(Payment $payment, int $addressCount, string $startsOn, string $endsOn): string
    {
        $startsFa = $this->toJalaliDate($startsOn);
        $endsFa = $this->toJalaliDate($endsOn);
        $amountToman = (int) ($payment->amount / 10);
        $lines = [];
        $lines[] = '✅ پرداخت موفق';
        $lines[] = '';
        $lines[] = '*شناسه تراکنش:* `' . (($payment->ref_number ?? '-') ?: '-') . '`';
        $lines[] = '*تعداد آدرس:* ' . number_format($addressCount);
        $lines[] = '*مبلغ پرداختی:* ' . number_format($amountToman) . ' تومان';
        $lines[] = '*بازه اشتراک:* ' . $startsFa . ' تا ' . $endsFa;
        $lines[] = '_روزانه حدود 2 پیامک ارسال می‌شود._';
        return implode("\n", $lines);
    }

    protected function buildSuccessMessageForPayment(Payment $payment): string
    {
        $user = User::findOrFail($payment->user_id);
        $sub = $user->subscriptions()->where('payment_id', $payment->id)->latest('id')->first();
        $startsOn = $sub ? (string) $sub->starts_on : Carbon::today()->toDateString();
        $endsOn = $sub ? (string) $sub->ends_on : Carbon::today()->addMonth()->toDateString();
        $addressCount = $sub ? (int) $sub->address_count : (int) $payment->address_count;
        return $this->buildSuccessMessage($payment, $addressCount, $startsOn, $endsOn);
    }

    /**
     * Build failure message in Markdown.
     */
    protected function buildFailureMessage(): string
    {
        $lines = [];
        $lines[] = '❌ پرداخت ناموفق';
        $lines[] = '';
        $lines[] = 'در صورت کسر وجه، طی حداکثر 72 ساعت بازگشت داده می‌شود.';
        return implode("\n", $lines);
    }

    /**
     * Convert a Gregorian date to a formatted Jalali date using Verta.
     */
    protected function toJalaliDate(string $date): string
    {
        $v = new Verta(Carbon::parse($date));
        return $v->format('Y/m/d');
    }

    protected function renderResultView(bool $ok, Payment $payment): Response
    {
        $user = User::find($payment->user_id);
        $sub = $user ? $user->subscriptions()->where('payment_id', $payment->id)->latest('id')->first() : null;
        return response()->view('payments.result', [
            'ok' => $ok,
            'payment' => $payment,
            'subscription' => $sub,
            'jStart' => $sub ? $this->toJalaliDate((string) $sub->starts_on) : null,
            'jEnd' => $sub ? $this->toJalaliDate((string) $sub->ends_on) : null,
            'amountToman' => (int) ($payment->amount / 10),
        ]);
    }
}


