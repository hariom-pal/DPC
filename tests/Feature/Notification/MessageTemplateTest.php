<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Domain\Authentication\Events\OtpRequested;
use App\Domain\Authentication\Listeners\SendOtpNotification;
use App\Domain\Authentication\Models\User;
use App\Domain\Notification\Models\MessageTemplate;
use App\Domain\Notification\Templates\MessageTemplates;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Enums\Role;
use App\Integrations\Contracts\SmsSender;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->actingAs($admin)->get(route('admin.message-templates.index'))->assertOk()->assertSee('otp.code')
            ->assertSee('When the AH issues a promotion order')->assertSee('Not sent at present');
        $this->get(route('admin.message-templates.edit', ['sitting.scheduled', 'email']))->assertOk()
            ->assertSee('id="template-when"', false)->assertSee('Sent to the committee members and the officers under consideration.');
        $this->get(route('admin.message-templates.edit', ['otp.code', 'sms']))->assertOk()
            ->assertSee('{code}')->assertSee('Your ODPC verification code is');

        $this->put(route('admin.message-templates.update', ['otp.code', 'sms']), [
            'body' => 'CGPSC ODPC: OTP {code}, valid {minutes} min.', 'dlt_template_id' => '1107161234567890123', 'is_active' => '1',
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

        $this->put(route('admin.message-templates.update', ['otp.code', 'sms']), ['body' => 'Your code is {otp}.', 'is_active' => '1'])
            ->assertSessionHasErrors('body');
        $this->put(route('admin.message-templates.update', ['otp.code', 'email']), ['subject' => 'OTP', 'body' => 'Valid {minutes} minutes.', 'is_active' => '1'])
            ->assertSessionHasErrors('body');
        $this->put(route('admin.message-templates.update', ['otp.code', 'sms']), ['body' => 'OTP {code}', 'dlt_template_id' => 'ABC', 'is_active' => '1'])
            ->assertSessionHasErrors('dlt_template_id');
        $this->assertSame(0, MessageTemplate::query()->count());
    }

    public function test_the_otp_cannot_be_switched_off_but_another_message_can(): void
    {
        $this->actingAs($this->admin());

        $this->put(route('admin.message-templates.update', ['otp.code', 'sms']), ['body' => 'OTP {code}', 'is_active' => '0'])
            ->assertSessionHasErrors('is_active');

        $this->put(route('admin.message-templates.update', ['result.published', 'sms']), ['body' => 'Result of {dpc} is out.', 'is_active' => '0'])
            ->assertSessionHasNoErrors();
        $this->assertNull(app(MessageTemplates::class)->sms('result.published', ['dpc' => 'DPC-1']), 'Switched off: not sent.');
        $this->assertNotNull(app(MessageTemplates::class)->email('result.published', ['dpc' => 'DPC-1']), 'The e-mail is its own template.');
    }

    public function test_restore_brings_back_the_built_in_wording_and_every_change_is_audited(): void
    {
        $this->actingAs($this->admin());

        $this->put(route('admin.message-templates.update', ['result.published', 'email']), ['subject' => 'Result {dpc}', 'body' => 'Result of {dpc} is out.', 'is_active' => '1'])
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

        $this->actingAs($ao)->get(route('admin.message-templates.index'))->assertForbidden();
        $this->actingAs($ao)->get(route('admin.message-templates.edit', ['nope', 'sms']))->assertForbidden();
    }
}
