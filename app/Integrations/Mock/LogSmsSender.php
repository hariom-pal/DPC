<?php

declare(strict_types=1);

namespace App\Integrations\Mock;

use App\Integrations\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

/**
 * Development SMS sender — records messages to the application log.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $mobile, string $message, ?string $templateId = null): void
    {
        Log::info('SMS dispatched', [
            'sender_id' => config('dpc.sms.sender_id'),
            'dlt_template_id' => $templateId,
            'to' => $mobile,
            'message' => $message,
        ]);
    }
}
