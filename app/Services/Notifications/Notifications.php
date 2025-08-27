<?php

namespace App\Services\Notifications;



use App\Models\User;
use App\Services\Notifications\Providers\Contracts\Provider;
use Exception;


/**
 * @method sendSms(User $user, string $text ,string $pattern)
 */
class Notifications
{
    /**
     * @throws Exception
     */
    public function __call($method, $arguments)
    {
        $providerPath = __NAMESPACE__ . '\Providers\\' . substr($method,4) . 'Provider';

        if (!class_exists($providerPath)) {
            throw new Exception("Class does not Exists");
        }

        $providerInstance = new $providerPath(...$arguments);

        if(!is_subclass_of($providerInstance,Provider::class)){
            throw new Exception("class must implements Modules\User\Services\Notifications\Providers\Contracts");
        }

        $providerInstance->send();
    }

}
