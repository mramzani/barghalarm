<?php

namespace App\Services\Payment\Gateway\Contract;

use App\Models\Payment;
use Illuminate\Http\Request;

interface GatewayInterface
{
    public function request(Payment $payment);
    public function verify(Request $request, Payment $payment): array;
    public function getName(): string;
    public function fee(int $amount): int;
}
