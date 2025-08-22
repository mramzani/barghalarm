<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramService;
use App\Services\Telegram\TelegramUpdateDispatcher;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    protected $telegramService;
    protected $dispatcher;

    public function __construct(TelegramService $telegramService, TelegramUpdateDispatcher $dispatcher)
    {
        $this->telegramService = $telegramService;
        $this->dispatcher = $dispatcher;
    }

    public function setWebhook(Request $request)
    {
        $webhookUrl = 'https://' . $request->server('HTTP_X_FORWARDED_HOST') . '/telegram/bot';
        return $this->telegramService->setWebhook($webhookUrl);
    }

    public function webhookInfo()
    {
        return $this->telegramService->webhookInfo();
    }

    public function handle()
    {
        $this->dispatcher->dispatch();

        return response()->json(['ok' => true]);
    }
}
