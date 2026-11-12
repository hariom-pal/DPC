<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

use App\Domain\Notification\Models\NotificationGateway;
use App\Domain\Notification\Services\NotificationGateways;
use App\Integrations\Contracts\SmsSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * SMS through the gateway the CGPSC Administrator has set up (2026-11-13): the
 * gateway's URL, account, Sender ID and DLT Entity ID are the same for every
 * message; only the DLT content template ID differs, and comes with each template.
 *
 * The parameter names of the gateway's HTTP API are settings too, because gateways
 * name them differently. The message text goes exactly as rendered from its
 * template — nothing added, trimmed or re-encoded here — and is never logged, OTPs
 * included. A text without a DLT Content Template ID is refused before any request.
 */
class HttpSmsSender implements SmsSender
{
    public function __construct(private readonly NotificationGateway $gateway) {}

    public function send(string $mobile, string $message, ?string $templateId = null): void
    {
        // No DLT Content Template ID, no SMS (2026-11-13): the operator rejects it, and
        // an ID is never guessed — not even for the OTP.
        if ($templateId === null || trim($templateId) === '') {
            Log::warning('SMS not sent: its text has no DLT Content Template ID.', ['to' => self::mask($mobile)]);

            throw new DltTemplateMissing('This SMS text has no DLT Content Template ID configured, so it was not sent.');
        }

        $names = array_replace(NotificationGateways::SMS_PARAMETERS, array_filter((array) $this->gateway->setting('parameters', [])));
        $values = [
            'username' => $this->gateway->setting('username'),
            'secret' => $this->gateway->secret,
            'sender_id' => $this->gateway->setting('sender_id'),
            'entity_id' => $this->gateway->setting('entity_id'),
            'template_id' => $templateId,
            'mobile' => $mobile,
            'message' => $message,
        ];

        $query = [];
        foreach ($values as $field => $value) {
            if ($value !== null && $value !== '' && ($names[$field] ?? '') !== '') {
                $query[$names[$field]] = $value;
            }
        }

        $url = (string) $this->gateway->setting('url');
        $masked = self::mask($mobile);

        try {
            $request = Http::timeout(15);
            $response = $this->gateway->setting('method', 'post') === 'get'
                ? $request->get($url, $query)
                : $request->asForm()->post($url, $query);
        } catch (Throwable $e) {
            Log::error('SMS gateway unreachable', ['to' => $masked, 'dlt_template_id' => $templateId, 'error' => $e->getMessage()]);

            throw new RuntimeException('The SMS gateway could not be reached.', 0, $e);
        }

        if (! $response->successful()) {
            Log::error('SMS gateway refused the message', ['to' => $masked, 'dlt_template_id' => $templateId, 'status' => $response->status()]);

            throw new RuntimeException('The SMS gateway refused the message (HTTP '.$response->status().').');
        }

        Log::info('SMS sent through the gateway', ['to' => $masked, 'dlt_template_id' => $templateId, 'status' => $response->status()]);
    }

    private static function mask(string $mobile): string
    {
        return str_repeat('x', max(0, strlen($mobile) - 4)).substr($mobile, -4);
    }
}
