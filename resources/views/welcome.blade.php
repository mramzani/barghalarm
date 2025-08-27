<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Bargh Alarm</title>
    <link rel="preconnect" href="//fdn.fontcdn.ir">
    <link rel="preconnect" href="//v1.fontapi.ir">
    <link href="https://v1.fontapi.ir/css/Estedad" rel="stylesheet">
    @vite(['resources/css/app.css','resources/js/app.js'])
    {{-- seo tags --}}
    <meta name="description" content="اطلاع‌رسانی قطعی برق مازندران">
    <meta name="keywords" content="مازندبرق, اطلاع‌رسانی قطعی برق, اطلاع‌رسانی قطعی برق مازندران, اطلاع‌رسانی قطعی برق شهرها و محله‌های استان مازندران">
    <meta name="author" content="مازندبرق">
    <meta name="robots" content="index, follow">
    <meta name="googlebot" content="index, follow">
    <meta name="google" content="notranslate">
    </head>
    <body class="antialiased">
        <div class="min-h-screen bg-gradient-to-b from-slate-950 to-slate-900 text-white">
            <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8 sm:py-12">
                <header class="flex items-center justify-between">
                    <a href="/" class="flex items-center gap-2">
                        <span class="text-2xl font-black tracking-tight">مازندبرق</span>
                    </a>
                    <nav class="hidden md:flex items-center gap-6 text-sm">
                        <a href="#features" class="text-slate-300 hover:text-white transition-colors">ویژگی‌ها</a>
                        <a href="{{ route('payments.invoice') }}" class="text-slate-300 hover:text-white transition-colors">خرید اشتراک</a>
                    </nav>
                </header>

                <main>
                    <section class="mt-14 sm:mt-20 text-center">
                        <h1 class="text-4xl sm:text-5xl md:text-6xl font-black leading-[1.2]">
                            اطلاع‌رسانی قطعی برق مازندران
                        </h1>
                        <p class="mt-6 text-base sm:text-lg md:text-xl text-slate-300 max-w-3xl mx-auto leading-8">
                            با مازندبرق، اعلان‌های فوری و دقیق درباره برنامه‌های قطعی برق شهرها و محله‌های استان مازندران را دریافت کنید. ساده، سریع و همیشه در کنار شما.
                        </p>
                        <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-3 sm:gap-4">
                            <a href="https://t.me/rinonotify_bot" class="inline-flex items-center justify-center rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white px-6 py-3 font-semibold shadow-lg shadow-emerald-600/20 transition-colors">
                                استفاده از مازندبرق
                            </a>
                            <a href="#features" class="inline-flex items-center justify-center rounded-xl border border-slate-700 text-slate-200 hover:bg-slate-800 px-6 py-3 font-semibold transition-colors">
                                آشنایی با قابلیت‌ها
                            </a>
                        </div>
                    </section>

                    <section id="features" class="mt-20 sm:mt-28 grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                        <div class="rounded-2xl border border-slate-800 bg-slate-900/50 p-6">
                            <div class="text-3xl">⚡</div>
                            <h3 class="mt-3 text-xl font-bold">اعلان‌های فوری</h3>
                            <p class="mt-2 text-slate-300 text-sm leading-7">
                                دریافت نوتیفیکیشن‌های لحظه‌ای درباره زمان‌بندی قطعی‌ها تا همیشه یک قدم جلوتر باشید.
                            </p>
                        </div>
                        <div class="rounded-2xl border border-slate-800 bg-slate-900/50 p-6">
                            <div class="text-3xl">📍</div>
                            <h3 class="mt-3 text-xl font-bold">پوشش محلی دقیق</h3>
                            <p class="mt-2 text-slate-300 text-sm leading-7">
                                انتخاب شهر و محله برای دریافت اطلاعات دقیق متناسب با موقعیت شما.
                            </p>
                        </div>
                        <div class="rounded-2xl border border-slate-800 bg-slate-900/50 p-6">
                            <div class="text-3xl">🔒</div>
                            <h3 class="mt-3 text-xl font-bold">امن و قابل اعتماد</h3>
                            <p class="mt-2 text-slate-300 text-sm leading-7">
                                حفظ حریم خصوصی و امنیت داده‌ها با استانداردهای روز.
                            </p>
                        </div>
                        <div class="rounded-2xl border border-slate-800 bg-slate-900/50 p-6">
                            <div class="text-3xl">🧾</div>
                            <h3 class="mt-3 text-xl font-bold">اشتراک ساده</h3>
                            <p class="mt-2 text-slate-300 text-sm leading-7">
                                فرآیند خرید اشتراک شفاف و سریع برای دسترسی کامل به امکانات.
                            </p>
                        </div>
                    </section>
                </main>

                <section class="mt-16 sm:mt-24">
                    <div class="rounded-2xl border border-slate-800 bg-slate-900/50 p-6 flex items-center justify-center">
                        <a referrerpolicy='origin' target='_blank' href='https://trustseal.enamad.ir/?id=406024&Code=7BHaWBkibIXgZ9jmJ5xyiXqnE5hcrDCm'><img referrerpolicy='origin' src='https://trustseal.enamad.ir/logo.aspx?id=406024&Code=7BHaWBkibIXgZ9jmJ5xyiXqnE5hcrDCm' alt='' style='cursor:pointer' code='7BHaWBkibIXgZ9jmJ5xyiXqnE5hcrDCm'></a>
                    </div>
                </section>

                <footer class="mt-8 text-center text-slate-400 text-xs sm:text-sm">
                    ساخته‌شده با ❤️ برای اطلاع‌رسانی بهتر به شهروندان
                </footer>
            </div>
        </div>
    </body>
</html>
    