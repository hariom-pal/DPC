<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * SMS delivery.
 *
 * The default "log" driver records messages to the application log for local
 * and development use. A production SMS gateway is pluggable by adding a
 * driver branch here (see the milestone questions file for the pending
 * gateway decision) — no calling code changes when the gateway is added.
 */
class SmsService
{
    public function send(string $mobile, string $message): void
    {
        $driver = (string) config('dpc.sms.driver', 'log');

        match ($driver) {
            'log' => $this->sendViaLog($mobile, $message),
            default => throw new \RuntimeException("Unsupported SMS driver [{$driver}]."),
        };
    }

    private function sendViaLog(string $mobile, string $message): void
    {
        Log::channel(config('logging.default'))->info('SMS dispatched', [
            'sender_id' => config('dpc.sms.sender_id'),
            'to' => $mobile,
            'message' => $message,
        ]);
    }
}
