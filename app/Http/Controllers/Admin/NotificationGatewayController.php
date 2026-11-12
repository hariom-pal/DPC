<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Notification\Models\MessageTemplate;
use App\Domain\Notification\Models\NotificationGateway;
use App\Domain\Notification\Services\NotificationGateways;
use App\Domain\Notification\Templates\MessageTemplateCatalog;
use App\Domain\Notification\Templates\MessageTemplates;
use App\Http\Controllers\Controller;
use App\Integrations\Sms\HttpSmsSender;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The e-mail (SMTP) and SMS gateway settings, kept by the CGPSC Administrator
 * (2026-11-13). A password or API key is written once and never shown again: left
 * blank, the stored one is kept. Each screen can send a test message.
 */
class NotificationGatewayController extends Controller
{
    public function __construct(private readonly NotificationGateways $gateways) {}

    public function edit(string $channel): View
    {
        $this->assertChannel($channel);

        $missingDlt = 0;

        if ($channel === NotificationGateway::SMS) {
            $templates = app(MessageTemplates::class);

            foreach (MessageTemplateCatalog::all() as $key => $definition) {
                $source = in_array(MessageTemplate::SMS, $definition['channels'], true) ? $templates->source($key, MessageTemplate::SMS) : null;
                $missingDlt += $source !== null && $source['dlt_template_id'] === null ? 1 : 0;
            }
        }

        return view('admin.notification-gateways.'.$channel, [
            'gateway' => $this->gateways->find($channel),
            'parameters' => NotificationGateways::SMS_PARAMETERS,
            'missingDlt' => $missingDlt,
        ]);
    }

    public function update(Request $request, string $channel): RedirectResponse
    {
        $this->assertChannel($channel);
        $existing = $this->gateways->find($channel);
        $enabled = $request->boolean('is_enabled');
        // The password stays as stored unless a new one is typed.
        $secretRule = $enabled && ($existing?->secret === null || $existing->secret === '') ? 'required' : 'nullable';

        if ($channel === NotificationGateway::SMTP) {
            $data = $request->validate([
                'is_enabled' => ['nullable', 'boolean'],
                'host' => [$enabled ? 'required' : 'nullable', 'string', 'max:255'],
                'port' => [$enabled ? 'required' : 'nullable', 'integer', 'between:1,65535'],
                'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
                'username' => ['nullable', 'string', 'max:255'],
                'secret' => [$request->filled('username') ? $secretRule : 'nullable', 'string', 'max:500'],
                'from_address' => [$enabled ? 'required' : 'nullable', 'email', 'max:255'],
                'from_name' => ['nullable', 'string', 'max:120'],
            ]);
            $settings = array_intersect_key($data, array_flip(['host', 'port', 'encryption', 'username', 'from_address', 'from_name']));
        } else {
            $data = $request->validate([
                'is_enabled' => ['nullable', 'boolean'],
                'url' => [$enabled ? 'required' : 'nullable', 'url:https,http', 'max:500'],
                'method' => ['required', Rule::in(['post', 'get'])],
                'username' => ['nullable', 'string', 'max:120'],
                'secret' => [$secretRule, 'string', 'max:500'],
                // The six-letter header the DLT portal approved, and the Principal Entity ID.
                'sender_id' => [$enabled ? 'required' : 'nullable', 'string', 'regex:/^[A-Za-z]{6}$/'],
                'entity_id' => [$enabled ? 'required' : 'nullable', 'regex:/^\d{10,30}$/'],
                'parameters' => ['nullable', 'array'],
                'parameters.*' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9_.\-\[\]]+$/'],
            ], [
                'sender_id.regex' => (string) __('admin.notification_gateways.sms.sender_id_format'),
                'entity_id.regex' => (string) __('admin.notification_gateways.sms.entity_id_format'),
            ]);
            $settings = array_intersect_key($data, array_flip(['url', 'method', 'username', 'sender_id', 'entity_id']));
            $settings['parameters'] = array_intersect_key((array) ($data['parameters'] ?? []), NotificationGateways::SMS_PARAMETERS);
        }

        $gateway = $existing ?? new NotificationGateway(['channel' => $channel]);
        $gateway->fill([
            'is_enabled' => $enabled,
            'settings' => $settings,
            'updated_by' => $request->user()?->id,
        ]);

        if ($request->filled('secret')) {
            $gateway->secret = (string) $request->input('secret');
        }

        $gateway->save();

        return redirect()->route('admin.notification-gateways.edit', $channel)->with('status', __('admin.notification_gateways.saved'));
    }

    /** A test message through the saved settings, to an address or number typed here. */
    public function test(Request $request, string $channel): RedirectResponse
    {
        $this->assertChannel($channel);
        $gateway = $this->gateways->find($channel);

        if ($gateway === null) {
            return back()->withErrors(['test_to' => __('admin.notification_gateways.save_first')]);
        }

        $to = (string) $request->validate([
            'test_to' => $channel === NotificationGateway::SMTP ? ['required', 'email'] : ['required', 'regex:/^[6-9]\d{9}$/'],
            // A test SMS goes under a real DLT template too; none is assumed (2026-11-13).
            'test_template_id' => $channel === NotificationGateway::SMS ? ['required', 'regex:/^\d{10,30}$/'] : ['prohibited'],
            'test_message' => $channel === NotificationGateway::SMS ? ['required', 'string', 'max:1000'] : ['prohibited'],
        ])['test_to'];

        try {
            if ($channel === NotificationGateway::SMTP) {
                $this->sendTestMail($gateway, $to);
            } else {
                (new HttpSmsSender($gateway))->send($to, (string) $request->input('test_message'), (string) $request->input('test_template_id'));
            }
        } catch (Throwable $e) {
            Log::warning('Notification gateway test failed', ['channel' => $channel, 'error' => $e->getMessage()]);

            return back()->withInput()->withErrors(['test_to' => __('admin.notification_gateways.test_failed', ['error' => $e->getMessage()])]);
        }

        return back()->with('status', __('admin.notification_gateways.test_sent', ['to' => $to]));
    }

    private function sendTestMail(NotificationGateway $gateway, string $to): void
    {
        // Through these settings even before they are enabled.
        $encryption = (string) $gateway->setting('encryption', 'tls');
        config([
            'mail.mailers.gateway_test' => [
                'transport' => 'smtp',
                'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
                'host' => (string) $gateway->setting('host'),
                'port' => (int) $gateway->setting('port', 587),
                'username' => $gateway->setting('username'),
                'password' => $gateway->secret,
                'timeout' => 15,
            ],
        ]);

        Mail::mailer('gateway_test')->raw((string) __('admin.notification_gateways.smtp.test_body'), function ($mail) use ($gateway, $to): void {
            $mail->from((string) $gateway->setting('from_address'), (string) $gateway->setting('from_name', config('app.name')))
                ->to($to)
                ->subject((string) __('admin.notification_gateways.smtp.test_subject'));
        });
    }

    private function assertChannel(string $channel): void
    {
        abort_unless(in_array($channel, [NotificationGateway::SMTP, NotificationGateway::SMS], true), 404);
    }
}
