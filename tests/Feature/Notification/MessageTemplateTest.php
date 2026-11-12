<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Domain\Authentication\Events\OtpRequested;
use App\Domain\Authentication\Exceptions\OtpDeliveryFailed;
use App\Domain\Authentication\Listeners\SendOtpNotification;
use App\Domain\Authentication\Models\User;
use App\Domain\Notification\Models\MessageTemplate;
use App\Domain\Notification\Models\NotificationGateway;
use App\Domain\Notification\Models\SmsDefaultDltId;
use App\Domain\Notification\Services\NotificationGateways;
use App\Domain\Notification\Templates\MessageTemplates;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Enums\Role;
use App\Integrations\Contracts\SmsSender;
use App\Integrations\Mock\LogSmsSender;
use App\Integrations\Sms\DltTemplateMissing;
use App\Integrations\Sms\HttpSmsSender;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OwenIt\Auditing\Models\Audit;
use Tests\Support\RecordingSmsSender;
use Tests\TestCase;

/**
 * SMS and e-mail templates kept by the CGPSC Administrator (2026-11-12).
 */
class MessageTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        return $admin;
    }

    public function test_without_a_template_the_built_in_wording_goes_out_unchanged(): void
    {
        $templates = app(MessageTemplates::class);

        $this->assertSame(
            'Your ODPC verification code is 123456. Valid for 10 minutes. Do not share it.',
            $templates->sms('otp.code', ['code' => '123456', 'minutes' => 10])['body'] ?? null,
        );
        $this->assertSame(
            (string) __('notifications.promotion_order.message', ['number' => 'F-1', 'date' => '01-12-2026', 'post' => 'EE', 'link' => 'L']),
            $templates->email('promotion_order.issued', ['number' => 'F-1', 'date' => '01-12-2026', 'post' => 'EE', 'link' => 'L'])['body'] ?? null,
        );
    }

    public function test_the_administrator_rewords_a_message_and_the_sms_carries_its_dlt_id(): void
    {
        $admin = $this->admin();

        // Each message says when it is sent and to whom (2026-11-12).
        $this->actingAs($admin)->get(route('admin.message-templates.index', 'sms'))->assertOk()->assertSee('otp.code')
            ->assertSee('When the AH issues a promotion order')->assertSee('Not sent at present');
        $this->get(route('admin.message-templates.edit', ['sitting.scheduled', 'email']))->assertOk()
            ->assertSee('id="template-when"', false)->assertSee('Sent to the committee members and the officers under consideration.');
        $this->get(route('admin.message-templates.edit', ['otp.code', 'sms']))->assertOk()
            ->assertSee('{code}')->assertSee('Your ODPC verification code is');

        $this->put(route('admin.message-templates.update', ['otp.code', 'sms']), [
            'body' => 'CGPSC ODPC: OTP {code}, valid {minutes} min.', 'dlt_template_id' => '1107161234567890123', 'status' => 'active',
        ])->assertSessionHasNoErrors();

        RecordingSmsSender::reset();
        $this->app->bind(SmsSender::class, RecordingSmsSender::class);
        config(['features.sms_gateway' => true]);
        app(SendOtpNotification::class)->handle(new OtpRequested('9000000001', OtpChannel::Sms, OtpPurpose::Login, '654321'));

        $minutes = (int) ceil(((int) config('dpc.otp.ttl_seconds')) / 60);
        $this->assertSame([['mobile' => '9000000001', 'message' => "CGPSC ODPC: OTP 654321, valid {$minutes} min.", 'template_id' => '1107161234567890123']], RecordingSmsSender::$sent);
    }

    public function test_a_template_must_use_its_own_placeholders_and_keep_the_required_ones(): void
    {
        $this->actingAs($this->admin());

        $this->put(route('admin.message-templates.update', ['otp.code', 'sms']), ['body' => 'Your code is {otp}.', 'dlt_template_id' => '1107161234567890123', 'status' => 'active'])
            ->assertSessionHasErrors('body');
        $this->put(route('admin.message-templates.update', ['otp.code', 'email']), ['subject' => 'OTP', 'body' => 'Valid {minutes} minutes.', 'status' => 'active'])
            ->assertSessionHasErrors('body');
        $this->put(route('admin.message-templates.update', ['otp.code', 'sms']), ['body' => 'OTP {code}', 'dlt_template_id' => 'ABC', 'status' => 'active'])
            ->assertSessionHasErrors('dlt_template_id');
        $this->assertSame(0, MessageTemplate::query()->count());
    }

    public function test_the_otp_cannot_be_switched_off_but_another_message_can(): void
    {
        $this->actingAs($this->admin());

        $this->put(route('admin.message-templates.update', ['otp.code', 'sms']), ['body' => 'OTP {code}', 'status' => 'inactive'])
            ->assertSessionHasErrors('status');

        $this->put(route('admin.message-templates.update', ['result.published', 'sms']), ['body' => 'Result of {dpc} is out.', 'status' => 'inactive'])
            ->assertSessionHasNoErrors();
        $this->assertNull(app(MessageTemplates::class)->sms('result.published', ['dpc' => 'DPC-1']), 'Switched off: not sent.');
        $this->assertNotNull(app(MessageTemplates::class)->email('result.published', ['dpc' => 'DPC-1']), 'The e-mail is its own template.');
    }

    public function test_restore_brings_back_the_built_in_wording_and_every_change_is_audited(): void
    {
        $this->actingAs($this->admin());

        $this->put(route('admin.message-templates.update', ['result.published', 'email']), ['subject' => 'Result {dpc}', 'body' => 'Result of {dpc} is out.', 'status' => 'active'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Result of DPC-1 is out.', app(MessageTemplates::class)->email('result.published', ['dpc' => 'DPC-1'])['body'] ?? null);

        $this->delete(route('admin.message-templates.destroy', ['result.published', 'email']))->assertRedirect();
        $this->assertStringContainsString('has been approved by CGPSC', (string) (app(MessageTemplates::class)->email('result.published', ['dpc' => 'DPC-1'])['body'] ?? ''));
        $this->assertSame(['created', 'deleted'], Audit::query()->where('auditable_type', (new MessageTemplate)->getMorphClass())->orderBy('id')->pluck('event')->all());
    }

    public function test_only_the_administrator_reaches_the_templates(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $ao = User::factory()->create();
        $ao->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($ao)->get(route('admin.message-templates.index', 'sms'))->assertForbidden();
        $this->actingAs($ao)->get(route('admin.notification-gateways.edit', 'smtp'))->assertForbidden();
        $this->actingAs($ao)->put(route('admin.notification-gateways.update', 'sms'), ['is_enabled' => '1'])->assertForbidden();
        $this->actingAs($ao)->get(route('admin.message-templates.edit', ['nope', 'sms']))->assertForbidden();
    }

    /**
     * SMS and e-mail templates on their own screens, and a status for each (2026-11-13):
     * Draft keeps the system default wording, Active uses the template, Inactive sends
     * nothing; an Active SMS needs its DLT Content Template ID.
     */
    public function test_each_channel_has_its_own_screen_and_a_template_is_draft_active_or_inactive(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.message-templates.index', 'sms'))->assertOk()
            ->assertSee(__('admin.message_templates.titles.sms'))->assertSee('data-channel="sms"', false)
            ->assertSee('otp.code|sms', false)->assertDontSee('otp.code|email', false);
        $this->get(route('admin.message-templates.index', 'sms'))->assertDontSee('department.invitation|sms', false);
        $this->get(route('admin.message-templates.index', 'email'))->assertOk()
            ->assertSee('department.invitation|email', false)->assertDontSee('otp.code|sms', false)
            ->assertSee(__('admin.message_templates.built_in'))->assertSee(__('admin.message_templates.statuses.active'));
        $this->get(route('admin.message-templates.index', 'whatsapp'))->assertNotFound();

        $values = ['dpc' => 'DPC-1'];
        $default = app(MessageTemplates::class)->sms('result.published', $values)['body'] ?? null;

        // An Active SMS without its DLT ID is refused.
        $this->put(route('admin.message-templates.update', ['result.published', 'sms']), ['body' => 'Result of {dpc} is out.', 'status' => 'active'])
            ->assertSessionHasErrors(['dlt_template_id' => __('admin.message_templates.dlt_required')]);

        // Draft: saved, but the system default still goes out.
        $this->put(route('admin.message-templates.update', ['result.published', 'sms']), ['body' => 'Result of {dpc} is out.', 'status' => 'draft'])
            ->assertSessionHasNoErrors();
        $this->assertSame($default, app(MessageTemplates::class)->sms('result.published', $values)['body'] ?? null);
        $this->assertNull(app(MessageTemplates::class)->sms('result.published', $values)['dlt_template_id'] ?? null);
        $this->get(route('admin.message-templates.index', 'sms'))->assertSee('data-status="draft"', false);

        // Active: this wording, with its DLT ID.
        $this->put(route('admin.message-templates.update', ['result.published', 'sms']), ['body' => 'Result of {dpc} is out.', 'dlt_template_id' => '1107161234567890123', 'status' => 'active'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['body' => 'Result of DPC-1 is out.', 'dlt_template_id' => '1107161234567890123'], app(MessageTemplates::class)->sms('result.published', $values));
        $this->get(route('admin.message-templates.index', 'sms'))->assertSee('data-status="active"', false)->assertSee(__('admin.message_templates.custom'));

        // The status picker offers Inactive only where a message may be switched off.
        $this->get(route('admin.message-templates.edit', ['otp.code', 'sms']))->assertSee('<option value="draft"', false)->assertDontSee('<option value="inactive"', false);
        $this->get(route('admin.message-templates.edit', ['result.published', 'sms']))->assertSee('<option value="inactive"', false);
    }

    public function test_the_admin_menu_lists_templates_and_settings_apart(): void
    {
        $this->actingAs($this->admin())->get(route('admin.notification-gateways.edit', 'smtp'))->assertOk()
            ->assertSee(route('admin.message-templates.index', 'sms'), false)
            ->assertSee(route('admin.message-templates.index', 'email'), false)
            ->assertSee(route('admin.notification-gateways.edit', 'sms'), false)
            ->assertSee(route('admin.notification-gateways.edit', 'smtp'), false);
    }

    /** SMTP settings (2026-11-13): saved with the password encrypted and never shown, and used once enabled. */
    public function test_smtp_settings_are_saved_encrypted_and_used_once_enabled(): void
    {
        $this->actingAs($this->admin());

        $this->put(route('admin.notification-gateways.update', 'smtp'), ['is_enabled' => '1', 'encryption' => 'tls'])
            ->assertSessionHasErrors(['host', 'port', 'from_address']);

        $this->put(route('admin.notification-gateways.update', 'smtp'), [
            'is_enabled' => '1', 'host' => 'smtp.cgpsc.test', 'port' => '587', 'encryption' => 'tls',
            'username' => 'odpc@cgpsc.test', 'secret' => 'smtp-pass-123', 'from_address' => 'no-reply@cgpsc.test', 'from_name' => 'CGPSC ODPC',
        ])->assertSessionHasNoErrors();

        $gateway = NotificationGateway::query()->where('channel', 'smtp')->sole();
        $this->assertSame('smtp-pass-123', $gateway->secret);
        $this->assertNotSame('smtp-pass-123', DB::table('notification_gateways')->value('secret'), 'Stored encrypted.');
        $this->assertStringNotContainsString('smtp-pass-123', (string) json_encode(Audit::query()->get()->toArray()));

        $this->get(route('admin.notification-gateways.edit', 'smtp'))->assertOk()
            ->assertSee('smtp.cgpsc.test')->assertDontSee('smtp-pass-123')->assertSee(__('admin.notification_gateways.secret_kept'));

        // Saving again without a password keeps the stored one.
        $this->put(route('admin.notification-gateways.update', 'smtp'), [
            'is_enabled' => '1', 'host' => 'smtp2.cgpsc.test', 'port' => '465', 'encryption' => 'ssl',
            'username' => 'odpc@cgpsc.test', 'from_address' => 'no-reply@cgpsc.test',
        ])->assertSessionHasNoErrors();
        $this->assertSame('smtp-pass-123', $gateway->fresh()?->secret);

        app(NotificationGateways::class)->applyMail();
        $this->assertSame(['smtp', 'smtp2.cgpsc.test', 465, 'smtps', 'smtp-pass-123', 'no-reply@cgpsc.test'], [
            config('mail.default'), config('mail.mailers.smtp.host'), config('mail.mailers.smtp.port'),
            config('mail.mailers.smtp.scheme'), config('mail.mailers.smtp.password'), config('mail.from.address'),
        ]);
    }

    public function test_smtp_settings_change_nothing_while_disabled(): void
    {
        $this->actingAs($this->admin());
        $before = config('mail.default');

        $this->put(route('admin.notification-gateways.update', 'smtp'), ['is_enabled' => '0', 'encryption' => 'tls', 'host' => 'smtp.cgpsc.test'])->assertSessionHasNoErrors();
        app(NotificationGateways::class)->applyMail();

        $this->assertSame($before, config('mail.default'));
    }

    /**
     * SMS settings (2026-11-13): the gateway, account, Sender ID and Entity ID are
     * common; each SMS carries its own DLT Content Template ID.
     */
    public function test_sms_goes_through_the_configured_gateway_with_each_templates_content_id(): void
    {
        $this->actingAs($this->admin());

        $this->put(route('admin.notification-gateways.update', 'sms'), ['is_enabled' => '1', 'method' => 'post', 'sender_id' => 'CG1', 'entity_id' => 'x'])
            ->assertSessionHasErrors(['url', 'secret', 'sender_id', 'entity_id']);

        $this->put(route('admin.notification-gateways.update', 'sms'), [
            'is_enabled' => '1', 'url' => 'https://sms.gateway.test/send', 'method' => 'post',
            'username' => 'cgpsc', 'secret' => 'sms-key-999', 'sender_id' => 'CGPSCR', 'entity_id' => '1101234567890123456',
            'parameters' => ['message' => 'msg', 'template_id' => ''],
        ])->assertSessionHasNoErrors();
        $this->put(route('admin.message-templates.update', ['otp.code', 'sms']), [
            'body' => 'CGPSC ODPC: OTP {code}, valid {minutes} min.', 'dlt_template_id' => '1107161234567890123', 'status' => 'active',
        ])->assertSessionHasNoErrors();

        Http::fake(['sms.gateway.test/*' => Http::response('OK', 200)]);
        $this->assertInstanceOf(HttpSmsSender::class, app(SmsSender::class));
        $this->assertTrue(app(NotificationGateways::class)->smsAvailable());

        app(SendOtpNotification::class)->handle(new OtpRequested('9000000001', OtpChannel::Sms, OtpPurpose::Login, '654321'));

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://sms.gateway.test/send'
                && $request['username'] === 'cgpsc' && $request['password'] === 'sms-key-999'
                && $request['senderid'] === 'CGPSCR' && $request['dlt_entity_id'] === '1101234567890123456'
                && $request['dlt_template_id'] === '1107161234567890123'
                && $request['mobile'] === '9000000001' && str_contains((string) $request['msg'], 'OTP 654321');
        });

        // Switched off: back to the server's own configuration.
        $this->put(route('admin.notification-gateways.update', 'sms'), ['is_enabled' => '0', 'method' => 'post'])->assertSessionHasNoErrors();
        $this->assertNotInstanceOf(HttpSmsSender::class, app(SmsSender::class));
        $this->assertSame('sms-key-999', NotificationGateway::query()->where('channel', 'sms')->sole()->secret, 'The key is kept.');
    }

    public function test_a_refused_sms_fails_loudly_without_logging_the_message(): void
    {
        $this->actingAs($this->admin());
        $this->put(route('admin.notification-gateways.update', 'sms'), [
            'is_enabled' => '1', 'url' => 'https://sms.gateway.test/send', 'method' => 'get',
            'secret' => 'k', 'sender_id' => 'CGPSCR', 'entity_id' => '1101234567890123456',
        ])->assertSessionHasNoErrors();
        Http::fake(['sms.gateway.test/*' => Http::response('denied', 403)]);

        // A test needs a registered text and its DLT ID; none is assumed.
        $this->post(route('admin.notification-gateways.test', 'sms'), ['test_to' => '9000000001'])
            ->assertSessionHasErrors(['test_template_id', 'test_message']);
        $this->post(route('admin.notification-gateways.test', 'sms'), ['test_to' => '9000000001', 'test_template_id' => '1107161234567890123', 'test_message' => 'ODPC test'])
            ->assertSessionHasErrors('test_to');
        $this->put(route('admin.message-templates.update', ['otp.code', 'sms']), [
            'body' => 'OTP {code}', 'dlt_template_id' => '1107161234567890123', 'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->expectException(OtpDeliveryFailed::class);
        app(SendOtpNotification::class)->handle(new OtpRequested('9000000001', OtpChannel::Sms, OtpPurpose::Login, '654321'));
    }

    /**
     * Turn the Administrator's SMS gateway on (2026-11-13).
     */
    private function enableSmsGateway(): void
    {
        $this->put(route('admin.notification-gateways.update', 'sms'), [
            'is_enabled' => '1', 'url' => 'https://sms.gateway.test/send', 'method' => 'post',
            'username' => 'cgpsc', 'secret' => 'sms-key-999', 'sender_id' => 'CGPSCR', 'entity_id' => '1101234567890123456',
        ])->assertSessionHasNoErrors();
        Http::fake(['sms.gateway.test/*' => Http::response('OK', 200)]);
    }

    /**
     * The System default SMS text keeps its text in the application and takes the DLT
     * ID the Administrator saves for it (2026-11-13); nothing is copied, nothing assumed.
     */
    public function test_a_system_default_sms_carries_the_dlt_id_saved_for_its_text(): void
    {
        $this->actingAs($this->admin());
        $templates = app(MessageTemplates::class);

        $sms = $templates->sms('result.published', ['dpc' => 'DPC-1']);
        $this->assertIsArray($sms);
        $this->assertArrayHasKey('dlt_template_id', $sms);
        $this->assertNull($sms['dlt_template_id'], 'No ID is assumed.');

        $this->get(route('admin.message-templates.edit', ['result.published', 'sms']))->assertOk()
            ->assertSee('id="default-dlt"', false)->assertSee('ODPC: the result of DPC {#var#} has been approved', false)
            ->assertSee('id="dlt-in-use-missing"', false);

        $this->put(route('admin.message-templates.default-dlt', 'result.published'), ['default_dlt_template_id' => 'ABC'])
            ->assertSessionHasErrors('default_dlt_template_id');
        $this->put(route('admin.message-templates.default-dlt', 'result.published'), ['default_dlt_template_id' => '1107160000000000001'])
            ->assertSessionHasNoErrors();

        $sms = $templates->sms('result.published', ['dpc' => 'DPC-1']);
        $this->assertSame('1107160000000000001', $sms['dlt_template_id'] ?? null);
        $this->assertStringStartsWith('ODPC: the result of DPC DPC-1 has been approved', (string) ($sms['body'] ?? ''));
        $this->assertSame(0, MessageTemplate::query()->count(), 'The text is not copied into a template.');
        $this->assertSame(1, SmsDefaultDltId::query()->count());

        // The SMS text is the one registered, whatever the screen language.
        app()->setLocale('hi');
        $this->assertSame($sms, $templates->sms('result.published', ['dpc' => 'DPC-1']));
        app()->setLocale('en');

        // Blank removes the ID again.
        $this->put(route('admin.message-templates.default-dlt', 'result.published'), ['default_dlt_template_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull($templates->defaultDltId('result.published'));
    }

    /** Customised and Active: its own text and its own ID; a Draft falls back to the default and the default's ID. */
    public function test_a_customised_sms_uses_its_own_id_and_a_draft_falls_back_to_the_defaults(): void
    {
        $this->actingAs($this->admin());
        $templates = app(MessageTemplates::class);
        $this->put(route('admin.message-templates.default-dlt', 'result.published'), ['default_dlt_template_id' => '1107160000000000001']);

        $this->put(route('admin.message-templates.update', ['result.published', 'sms']), [
            'body' => 'CGPSC: result of {dpc} is out.', 'dlt_template_id' => '1107160000000000002', 'status' => 'active',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['body' => 'CGPSC: result of DPC-1 is out.', 'dlt_template_id' => '1107160000000000002'], $templates->sms('result.published', ['dpc' => 'DPC-1']));

        // Draft: the System default text, with the System default's ID — never the Draft's.
        $this->put(route('admin.message-templates.update', ['result.published', 'sms']), [
            'body' => 'CGPSC: result of {dpc} is out.', 'dlt_template_id' => '1107160000000000002', 'status' => 'draft',
        ])->assertSessionHasNoErrors();
        $sms = $templates->sms('result.published', ['dpc' => 'DPC-1']);
        $this->assertSame('1107160000000000001', $sms['dlt_template_id'] ?? null);
        $this->assertStringStartsWith('ODPC: the result of DPC DPC-1', (string) ($sms['body'] ?? ''));

        // Inactive: not sent at all; the OTP can never be Inactive.
        $this->put(route('admin.message-templates.update', ['result.published', 'sms']), ['body' => 'x {dpc}', 'status' => 'inactive'])->assertSessionHasNoErrors();
        $this->assertNull($templates->sms('result.published', ['dpc' => 'DPC-1']));
        $this->put(route('admin.message-templates.update', ['otp.code', 'sms']), ['body' => 'OTP {code}', 'status' => 'inactive'])->assertSessionHasErrors('status');
    }

    /**
     * With the real gateway on, an SMS whose text has no DLT ID is not sent — the OTP
     * included — and the Administrator is warned (2026-11-13).
     */
    public function test_with_the_gateway_on_an_sms_without_a_dlt_id_is_not_sent_and_the_admin_is_warned(): void
    {
        $this->actingAs($this->admin());
        $this->enableSmsGateway();

        $this->get(route('admin.message-templates.index', 'sms'))->assertOk()
            ->assertSee('id="dlt-missing-warning"', false)
            ->assertSee(__('admin.message_templates.dlt_missing'));
        $this->get(route('admin.notification-gateways.edit', 'sms'))->assertOk()->assertSee('id="dlt-missing-warning"', false);

        // The OTP: refused before the gateway is called, and the user is told it failed.
        try {
            app(SendOtpNotification::class)->handle(new OtpRequested('9000000001', OtpChannel::Sms, OtpPurpose::Login, '654321'));
            $this->fail('An OTP without a DLT ID must not be reported as sent.');
        } catch (OtpDeliveryFailed) {
        }
        Http::assertNothingSent();

        $this->expectException(DltTemplateMissing::class);
        app(SmsSender::class)->send('9000000001', 'Any text', null);
    }

    public function test_with_the_gateway_on_the_otp_goes_once_its_default_text_has_an_id(): void
    {
        $this->actingAs($this->admin());
        $this->enableSmsGateway();
        $this->put(route('admin.message-templates.default-dlt', 'otp.code'), ['default_dlt_template_id' => '1107160000000000009'])->assertSessionHasNoErrors();

        app(SendOtpNotification::class)->handle(new OtpRequested('9000000001', OtpChannel::Sms, OtpPurpose::Login, '654321'));

        $minutes = (int) ceil(((int) config('dpc.otp.ttl_seconds')) / 60);
        Http::assertSent(fn ($request): bool => $request['dlt_template_id'] === '1107160000000000009'
            && $request['message'] === "Your ODPC verification code is 654321. Valid for {$minutes} minutes. Do not share it.");
        $this->get(route('admin.message-templates.index', 'sms'))->assertSee('data-template="otp.code|sms"', false)
            ->assertSee('1107160000000000009');
    }

    /** With no gateway, SMS is logged locally as before, ID or not (2026-11-13). */
    public function test_with_the_gateway_off_sms_is_logged_locally_as_before(): void
    {
        $this->actingAs($this->admin());
        config(['features.sms_gateway' => false]);
        Http::fake();
        Log::spy();

        $this->assertInstanceOf(LogSmsSender::class, app(SmsSender::class));
        $this->assertFalse(app(NotificationGateways::class)->smsAvailable());

        app(SmsSender::class)->send('9000000001', 'A text without an ID', null);
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []): bool => $message === 'SMS dispatched' && $context['dlt_template_id'] === null);

        // An OTP by SMS is not attempted while no SMS can go out.
        app(SendOtpNotification::class)->handle(new OtpRequested('9000000001', OtpChannel::Sms, OtpPurpose::Login, '654321'));
        Http::assertNothingSent();
    }
}
