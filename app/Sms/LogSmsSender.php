<?php

declare(strict_types=1);

namespace App\Sms;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

/**
 * Development SMS sender — records messages to the application log.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $mobile, string $message): void
    {
        Log::info('SMS dispatched', [
            'sender_id' => config('dpc.sms.sender_id'),
            'to' => $mobile,
            'message' => $message,
        ]);
    }
}
