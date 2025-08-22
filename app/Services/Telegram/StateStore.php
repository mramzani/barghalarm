<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;

/**
 * Class StateStore
 *
 * Provides a small abstraction over cache for storing per-chat ephemeral state.
 */
class StateStore
{
    /**
     * Persist a state array for a given Telegram chat id.
     *
     * @param int|string $chatId
     * @param array $state
     */
    public function set(int|string $chatId, array $state): void
    {
        Cache::put('tg:state:' . $chatId, $state, now()->addMinutes(10));
    }

    /**
     * Retrieve stored state for a given Telegram chat id.
     *
     * @param int|string $chatId
     * @return array{step?:string,city_id?:int,pending_add_code?:int,address_id?:int}
     */
    public function get(int|string $chatId): array
    {
        /** @var array{step?:string,city_id?:int,pending_add_code?:int,address_id?:int} $state */
        $state = Cache::get('tg:state:' . $chatId, []);

        return $state;
    }

    /**
     * Clear any stored state for a given Telegram chat id.
     *
     * @param int|string $chatId
     */
    public function clear(int|string $chatId): void
    {
        Cache::forget('tg:state:' . $chatId);
    }
}


