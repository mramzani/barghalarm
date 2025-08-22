<?php

namespace Tests\Unit;

use App\Services\Telegram\StateStore;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StateStoreTest extends TestCase
{
    public function test_set_get_and_clear_state(): void
    {
        $store = new StateStore();
        $chatId = 12345;
        $state = ['step' => 'await_keyword', 'city_id' => 7];

        $store->set($chatId, $state);
        $this->assertSame($state, $store->get($chatId));

        $store->clear($chatId);
        $this->assertSame([], $store->get($chatId));
    }
}


