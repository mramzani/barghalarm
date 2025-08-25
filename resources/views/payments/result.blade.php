<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نتیجه پرداخت</title>
    <link rel="preconnect" href="//fdn.fontcdn.ir">
<link rel="preconnect" href="//v1.fontapi.ir">
<link href="https://v1.fontapi.ir/css/Estedad" rel="stylesheet">

<style>
    body {
        font-family: 'Estedad', sans-serif;
    }
</style>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="bg-white shadow rounded-xl p-6">
            @if($ok)
                <div class="flex items-center gap-3 text-green-600">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm13.36-2.59a.75.75 0 0 0-1.22-.88l-3.3 4.57-1.62-1.62a.75.75 0 1 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.09l3.87-5.29Z" clip-rule="evenodd"/></svg>
                    <h1 class="text-xl font-bold">پرداخت موفق</h1>
                </div>
                <div class="mt-4 space-y-2 text-gray-700">
                    <div><span class="font-medium">شناسه تراکنش:</span> <span dir="ltr">{{ $payment->ref_number ?? '-' }}</span></div>
                    <div><span class="font-medium">مبلغ:</span> {{ number_format($amountToman) }} تومان</div>
                    @if($subscription)
                        <div><span class="font-medium">بازه اشتراک:</span> {{ $jStart }} تا {{ $jEnd }}</div>
                        <div><span class="font-medium">تعداد آدرس:</span> {{ number_format($subscription->address_count) }}</div>
                    @endif
                </div>
                <div class="mt-6">
                    <a href="https://t.me/{{ config('services.telegram.bot_username') }}" class="inline-flex items-center justify-center w-full rounded-lg bg-green-600 text-white py-2.5 hover:bg-green-700 transition">بازگشت به ربات</a>
                </div>
            @else
                <div class="flex items-center gap-3 text-red-600">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25ZM10.72 8.72a.75.75 0 1 0-1.06 1.06L10.94 11l-1.28 1.22a.75.75 0 1 0 1.06 1.06L12 12.06l1.22 1.28a.75.75 0 1 0 1.06-1.06L13.06 11l1.28-1.22a.75.75 0 1 0-1.06-1.06L12 9.94 10.72 8.72Z" clip-rule="evenodd"/></svg>
                    <h1 class="text-xl font-bold">پرداخت ناموفق</h1>
                </div>
                <div class="mt-4 space-y-2 text-gray-700">
                    <p>در صورت کسر وجه، طی حداکثر ۷۲ ساعت بازگشت داده می‌شود.</p>
                </div>
                <div class="mt-6 flex gap-3">
                    <a href="/" class="inline-flex items-center justify-center flex-1 rounded-lg bg-gray-100 text-gray-800 py-2.5 hover:bg-gray-200 transition">بازگشت به سایت</a>
                    <a href="https://t.me/{{ config('services.telegram.bot_username') }}" class="inline-flex items-center justify-center flex-1 rounded-lg bg-blue-600 text-white py-2.5 hover:bg-blue-700 transition">بازگشت به ربات</a>
                </div>
            @endif
        </div>
        <p class="mt-4 text-center text-xs text-gray-400">مازندبرق</p>
    </div>
</body>
</html>


