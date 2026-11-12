<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Models\NotificationGateway;
use App\Enums\Feature;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The e-mail (SMTP) and SMS settings the CGPSC Administrator keeps (2026-11-13), and
 * where they take effect. A channel not enabled here is delivered as configured in
 * the environment, exactly as before.
 */
class NotificationGateways
{
    /** Default parameter names of the SMS gateway's HTTP API; each may be renamed. */
    public const SMS_PARAMETERS = [
        'username' => 'username',
        'secret' => 'password',
        'sender_id' => 'senderid',
        'entity_id' => 'dlt_entity_id',
        'template_id' => 'dlt_template_id',
        'mobile' => 'mobile',
        'message' => 'message',
    ];

    /**
     * Whether SMS can be delivered at all: the Administrator's gateway is enabled, or
     * the environment's flag is on (2026-11-13).
     */
    public function smsAvailable(): bool
    {
        return $this->enabled(NotificationGateway::SMS) !== null || Feature::SmsGateway->enabled();
    }

    public function find(string $channel): ?NotificationGateway
    {
        try {
            if (! Schema::hasTable('notification_gateways')) {
                return null;
            }

            return NotificationGateway::query()->where('channel', $channel)->first();
        } catch (Throwable) {
            // No database yet (an install, a console command before migrating).
            return null;
        }
    }

    public function enabled(string $channel): ?NotificationGateway
    {
        $gateway = $this->find($channel);

        return $gateway !== null && $gateway->is_enabled ? $gateway : null;
    }

    /**
     * Point the mailer at the Administrator's SMTP server, when one is enabled.
     * Called once, as the mail manager is first resolved.
     */
    public function applyMail(): void
    {
        $smtp = $this->enabled(NotificationGateway::SMTP);

        if ($smtp === null) {
            return;
        }

        $encryption = (string) $smtp->setting('encryption', 'tls');

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => (string) $smtp->setting('host'),
            'mail.mailers.smtp.port' => (int) $smtp->setting('port', 587),
            // smtps is implicit TLS (465); STARTTLS (587) and none use plain smtp.
            'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.username' => $smtp->setting('username'),
            'mail.mailers.smtp.password' => $smtp->secret,
            'mail.from.address' => (string) $smtp->setting('from_address'),
            'mail.from.name' => (string) $smtp->setting('from_name', config('app.name')),
        ]);
    }
}
