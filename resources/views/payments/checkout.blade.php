<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تایید شماره موبایل و پرداخت</title>
    <link rel="preconnect" href="//fdn.fontcdn.ir">
    <link rel="preconnect" href="//v1.fontapi.ir">
    <link href="https://v1.fontapi.ir/css/Estedad" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>body{font-family:'Estedad',sans-serif}</style>
</head>
<body class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <form id="payment-form" action="{{ route('payments.invoice.post', [], false) }}" method="post" class="bg-white rounded-xl shadow p-6 space-y-5">
            @csrf
            <input type="hidden" name="chat_id" value="{{ $chatId }}">
            <h1 class="text-xl font-bold">تایید شماره موبایل و پرداخت</h1>

            @if($uncoveredCount === 0)
                <div class="text-green-700 bg-green-50 rounded-lg p-3">
                    شما برای تمام آدرس‌ها اشتراک فعال دارید.
                    @if($endsFa)
                        <div class="mt-1 text-sm">اعتبار اشتراک‌ها تا: {{ $endsFa }}</div>
                    @endif
                </div>
            @else
                <div class="text-gray-700 bg-gray-50 rounded-lg p-3">
                    <div>آدرس‌های بدون اشتراک: <span class="font-bold">{{ $uncoveredCount }}</span></div>
                    <div>هزینه هر آدرس (ماهانه): <span class="font-bold">{{ number_format($pricePer) }} تومان</span></div>
                    <div>جمع ماهانه قابل پرداخت: <span class="font-bold">{{ number_format($monthly) }} تومان</span></div>
                </div>
            @endif

            <div>
                <label class="block text-sm mb-1">شماره موبایل (الزامی)</label>
                <input type="tel" name="mobile" value="{{ old('mobile', $user->mobile) }}" dir="ltr" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="مثال: 0912xxxxxxx یا +98912xxxxxxx" required>
                @error('mobile')
                    <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                @enderror
            </div>

            @error('gateway')
                <div class="text-sm text-red-600">{{ $message }}</div>
            @enderror

            <div class="text-xs text-gray-500 leading-6">
                با توجه به عدم استفاده از کد تایید، مسئولیت وارد کردن شماره موبایل به‌صورت صحیح به عهده شماست. در صورت درج شماره اشتباه، پیامک‌ها به شماره ثبت‌شده ارسال می‌شود و وجه قابل استرداد نیست.
            </div>

            <button type="button" id="pay-btn" class="w-full rounded-lg bg-blue-600 text-white py-2.5 hover:bg-blue-700 transition disabled:opacity-50" @if($uncoveredCount===0) disabled @endif>
                ادامه و ورود به درگاه پرداخت
            </button>
        </form>

        <p class="mt-4 text-center text-xs text-gray-400">مازندبرق</p>
    </div>

    <script>
    document.getElementById('pay-btn')?.addEventListener('click', function(){
        const form = document.getElementById('payment-form');
        const mobile = form.querySelector('input[name="mobile"]').value.trim();
        if(!mobile){ form.submit(); return; }
        Swal.fire({
            title: 'تایید شماره موبایل',
            html: 'آیا از صحت شماره موبایل زیر اطمینان دارید؟<br><b dir="ltr">'+mobile+'</b>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'بله، مطمئنم',
            cancelButtonText: 'ویرایش',
            confirmButtonColor: '#16a34a',
            cancelButtonColor: '#ef4444',
        }).then((result)=>{
            if(result.isConfirmed){ form.submit(); }
        });
    });
    </script>
</body>
</html>


