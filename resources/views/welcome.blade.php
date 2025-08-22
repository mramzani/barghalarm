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
    </head>
    <body class="antialiased">
        <div class="mx-auto max-w-7xl px-4 py-8">
            <div class="bg-white shadow-sm rounded-2xl border border-gray-200 overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-200 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 class="text-xl font-bold text-gray-900">نتایج خاموشی</h1>
                        <p class="mt-1 text-sm text-gray-500">نمایش نتایج بر اساس فیلترهای انتخاب شده</p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1 text-blue-700 border border-blue-200">
                            از: <?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <span class="inline-flex items-center gap-2 rounded-full bg-indigo-50 px-3 py-1 text-indigo-700 border border-indigo-200">
                            تا: <?php echo htmlspecialchars($to, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-emerald-700 border border-emerald-200">
                        
                            منطقه: <?php echo htmlspecialchars($area, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                </div>
    
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <?php if (!empty($headers)) { ?>
                        <thead class="bg-gray-50 text-gray-700">
                            <tr>
                                <?php foreach ($headers as $h): ?>
                                <th class="px-4 py-3 text-center font-semibold whitespace-nowrap border-b border-gray-200"><?php echo htmlspecialchars($h, ENT_QUOTES, 'UTF-8'); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <?php } ?>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (!empty($rows)): ?>
                                <?php foreach ($rows as $r): ?>
                                <tr class="hover:bg-gray-50">
                                    <?php foreach ($r as $c): ?>
                                    <td class="px-4 py-3 text-gray-800 whitespace-pre-wrap align-top"><?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td class="px-6 py-10 text-center text-gray-500" colspan="<?php echo max(1, count($headers)); ?>">
                                        <span class="block text-base">موردی برای نمایش وجود ندارد</span>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!empty($rows)) { ?>
                <div class="px-6 py-4 border-t border-gray-200 text-xs text-gray-500">
                    تعداد ردیف‌ها: <span class="font-medium text-gray-700"><?php echo count($rows); ?></span>
                </div>
                <?php } ?>
            </div>
        </div>
    </body>
</html>
