<?php

namespace App\Providers;

use App\Services\Telegram\TelegramService;
use Illuminate\Support\ServiceProvider;

class TelegramServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(TelegramService::class, function ($app) {
            $config = config('services.telegram');
            return new TelegramService(
                $config['bot_token'],
                true,
                // [
                //     'url' => $config['proxy']['url'],
                //     'port' => $config['proxy']['port'],
                //     'type' => $config['proxy']['type'],
                //     'auth' => $config['proxy']['auth'],
                // ]
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
