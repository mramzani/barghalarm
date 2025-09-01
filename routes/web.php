<?php

use App\Http\Controllers\Api\TelegramController;
use App\Models\Address;
use App\Models\Area;
use App\Models\Blackout;
use App\Models\City;
use App\Services\Scraper\OutageScraper;
use App\Services\Telegram\Telegram;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Hekmatinasser\Verta\Verta;
use App\Http\Controllers\PaymentController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/


Route::get('/import', function () {
    $scraper = new OutageScraper();
    [$headers, $rows] = $scraper->searchOutages('1404/06/10', '1404/06/10','2');
    dd($headers, $rows);
});
// Legacy route disabled in favor of scheduled command (blackouts:import)


Route::get('/',function(){
    return view('welcome');
});

Route::post('/telegram/bot', [TelegramController::class, 'handle']);
Route::get('/info', [TelegramController::class, 'webhookInfo']);
Route::get('/set', [TelegramController::class, 'setWebhook']);

// Payments
Route::get('/payments/invoice', [PaymentController::class, 'invoice'])->name('payments.invoice');
Route::post('/payments/invoice', [PaymentController::class, 'invoicePost'])->name('payments.invoice.post');
Route::get('/payments/callback', [PaymentController::class, 'callback'])->name('payments.callback');