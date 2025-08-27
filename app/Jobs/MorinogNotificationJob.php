<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Notifications\Notifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MorinogNotificationJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public User $user, public array $args)
    {
        $this->user = $user;
        $this->args = $args;
    }

    /**
     * Execute the job.
     */
    public function handle(Notifications $notifications): void
    {
        $pattern = "123456";
        $notifications->sendSms($this->user,$this->args,$pattern);
    }
}
