<?php

namespace App\Services\Notifications\Providers;


use App\Models\User;
use App\Services\Notifications\Providers\Contracts\Provider;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsProvider implements Provider
{
    private User $user;
    private array $args;
    private string $pattern;
    private string $consoleKey;
    private string $apiUrl;
    private string $sendFrom;

    /**
     * @param User $user
     * @param string $text
     * @param string $pattern
     */
    public function __construct(User $user, array $args,string $pattern)
    {
        $this->user = $user;
        $this->args = $args;
        $this->pattern = $pattern;
        $this->consoleKey = config('services.meli_payamak.console_key');
        $this->apiUrl = "https://console.melipayamak.com/api/send/shared/";
        $this->sendFrom = config('services.meli_payamak.send_from');
    }


    /**
     *
     * @throws Exception
     */
    public function send()
    {
        Log::info('send sms',['user'=>json_encode($this->user),'args'=>json_encode($this->args),'pattern'=>$this->pattern]);
       // $response = $this->sendWithConsole($this->args);

        // if ($response->status() !== 200) {
        //     Log::warning('Error in send message with code: ' . $response->body());
        //     throw new Exception('error in send message please check log file');
        // }
        return null;
    }

    /**
     * @return \Illuminate\Http\Client\Response
     */
    private function sendWithConsole(array $args)
    {
        return Http::post("{$this->apiUrl}{$this->consoleKey}", [
            'bodyId' => $this->pattern,
            'to' => $this->user->mobile,
            'args' => $args,
        ]);
    }


}
