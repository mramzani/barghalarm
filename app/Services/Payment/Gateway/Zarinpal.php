<?php

namespace App\Services\Payment\Gateway;

use App\Models\Payment;
use App\Services\Payment\Gateway\Contract\GatewayInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

class Zarinpal implements GatewayInterface
{

    private $merchantID;
   // private $zarinGate;
    private $sandbox;
    private $callbackUrl;
    private $requestPaymentUrl;
    private $startPaymentUrl;
    private $verifyPaymentUrl;
    private $sandboxRequestPaymentUrl;
    public function __construct()
    {
        $this->merchantID = config('services.zarinpal.merchantID');
        //$this->zarinGate = config('services.zarinpal.zarinGate');
        $this->sandbox = config('services.zarinpal.sandbox');
        $this->callbackUrl = URL::to('/payments/callback');
        $this->requestPaymentUrl = "https://api.zarinpal.com/pg/v4/payment/request.json";
        $this->startPaymentUrl = "https://www.zarinpal.com/pg/StartPay/";
        $this->verifyPaymentUrl = "https://api.zarinpal.com/pg/v4/payment/verify.json";
        $this->sandboxRequestPaymentUrl = "https://sandbox.zarinpal.com/pg/v4/payment/request.json";
    }


    public function request(Payment $payment)
    {

        $input = [
            'merchant_id' => $this->merchantID,
            'amount' => $payment->amount,
            'description' => 'پرداخت سفارش ' . $payment->id,
            'callback_url' => $this->callbackUrl . '?payment_id=' . $payment->id,
            'metadata' => [
                'order_id' => (string) $payment->id,
            ],
        ];
        $requestUrl = $this->sandbox ? $this->sandboxRequestPaymentUrl : $this->requestPaymentUrl;
        $response = Http::asJson()->post($requestUrl, $input);
        return $response->json();
    }

    public function verify(Request $request, Payment $payment): array
    {
        $status = (string) $request->query('Status', $request->input('Status', ''));
        $authority = (string) $request->query('Authority', $request->input('Authority', ''));
        if ($authority === '') {
            return ['ok' => false, 'message' => 'authority missing'];
        }
        $verifyUrl = $this->sandbox ? 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json' : $this->verifyPaymentUrl;
        $payload = [
            'merchant_id' => $this->merchantID,
            'amount' => (int) $payment->amount,
            'authority' => $authority,
        ];
        $response = Http::asJson()->post($verifyUrl, $payload);
        return (array) $response->json();
    }

    public function getName(): string
    {
        return 'zarinpal';
    }

    public function fee(int $amount): int
    {
       return 0;
    }
}
