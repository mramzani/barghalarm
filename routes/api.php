<?php

use App\Services\Telegram\Telegram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/telegram/webhook', function (Request $request) {
    $telegram = new Telegram(env('TELEGRAM_BOT_TOKEN'), true);
    $telegram->setData($request->all());

    $chatId = null;
    try {
        $chatId = $telegram->ChatID();
    } catch (\Throwable $e) {
        Log::error('Failed to extract chat_id from update', ['exception' => $e, 'payload' => $request->all()]);
    }

    Log::info('Telegram update received', [
        'chat_id' => $chatId,
        'text' => $telegram->Text(),
        'update_type' => $telegram->getUpdateType(),
    ]);

    if ($chatId) {
        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Hello, world!'
        ]);
    }

    return response()->json(['ok' => true]);
});
