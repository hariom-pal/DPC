<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Authentication\Models\OtpVerification;
use App\Domain\Authentication\Models\SecurityEvent;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * My Profile (2026-11-03): every user sees their account in full and changes their
 * e-mail and mobile, proved by OTP on both at once. The fixed test code is 123456
 * for both channels (phpunit.xml).
 */
class MyProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $ao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();

        $department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education', 'status' => 'registered']);
        $this->ao = User::factory()->assignedTo($department, Role::AuthorizedOfficer)->create([
            'email' => 'ao@cg.gov.in', 'mobile' => '9000000001', 'employee_number' => 'HE/7',
        ]);
    }

    public function test_every_user_sees_their_profile(): void
    {
        $admin = User::factory()->create(['email' => 'admin@cgpsc.gov.in']);
        $admin->assignRole(Role::Admin->value);
        $candidate = User::factory()->create();
        $candidate->assignRole(Role::PromotionCandidate->value);

        $this->actingAs($this->ao)->get(route('account.show'))->assertOk()
            ->assertSee('ao@cg.gov.in')->assertSee('9000000001')->assertSee('HE/7')->assertSee('Higher Education')
            ->assertSee('id="account-contact-start"', false);
        $this->actingAs($admin)->get(route('account.show'))->assertOk()->assertSee('admin@cgpsc.gov.in');
        $this->actingAs($candidate)->get(route('account.show'))->assertOk();
        $this->actingAs($this->ao)->get(route('dashboard'))->assertSee(route('account.show'), false);

        auth()->logout();
        $this->get(route('account.show'))->assertRedirect();
    }

    public function test_a_new_email_and_mobile_are_saved_only_when_both_codes_are_right(): void
    {
        $this->actingAs($this->ao)->post(route('account.contact.start'), ['email' => 'New.AO@cg.nic.in', 'mobile' => '9111111111', 'current_password' => 'password'])
            ->assertRedirect(route('account.show'))->assertSessionHasNoErrors();

        // A code went to each new address; nothing changed yet.
        $this->assertSame(1, OtpVerification::query()->where('identifier', 'new.ao@cg.nic.in')->where('purpose', 'contact_change')->count());
        $this->assertSame(1, OtpVerification::query()->where('identifier', '9111111111')->where('purpose', 'contact_change')->count());
        $this->assertSame('ao@cg.gov.in', $this->ao->fresh()->email);
        $this->actingAs($this->ao)->get(route('account.show'))->assertSee('id="account-contact-verify"', false)->assertSee('mobile_code', false);

        // One wrong code: nothing changes, and the right e-mail code is not burnt.
        $this->actingAs($this->ao)->post(route('account.contact.verify'), ['email_code' => '123456', 'mobile_code' => '000000'])
            ->assertSessionHasErrors('email_code');
        $this->assertSame('9000000001', $this->ao->fresh()->mobile);

        $this->actingAs($this->ao)->post(route('account.contact.verify'), ['email_code' => '123456', 'mobile_code' => '123456'])
            ->assertRedirect(route('account.show'))->assertSessionHasNoErrors();

        $ao = $this->ao->fresh();
        $this->assertSame(['new.ao@cg.nic.in', '9111111111'], [$ao->email, $ao->mobile]);
        $this->assertNotNull($ao->email_verified_at);
        $this->assertNotNull($ao->mobile_verified_at);

        // Logged by field name, never by value.
        $event = SecurityEvent::query()->where('event', 'account.contact_changed')->sole();
        $this->assertSame('email,mobile', $event->context['fields']);
        $this->assertStringNotContainsString('9111111111', (string) json_encode($event->context));
        $this->assertSame('new.ao@cg.nic.in', DB::table('employees')->where('employee_number', 'HE/7')->value('email'));
    }

    public function test_an_unchanged_one_is_proved_on_its_present_value(): void
    {
        $this->actingAs($this->ao)->post(route('account.contact.start'), ['email' => 'ao@cg.gov.in', 'mobile' => '9222222222', 'current_password' => 'password'])->assertSessionHasNoErrors();

        $this->assertSame(1, OtpVerification::query()->where('identifier', 'ao@cg.gov.in')->where('purpose', 'contact_change')->count());
        $this->assertSame(1, OtpVerification::query()->where('identifier', '9222222222')->count());

        $this->actingAs($this->ao)->post(route('account.contact.verify'), ['email_code' => '123456', 'mobile_code' => '123456'])->assertSessionHasNoErrors();
        $this->assertSame(['ao@cg.gov.in', '9222222222'], [$this->ao->fresh()->email, $this->ao->fresh()->mobile]);
        $this->assertSame('mobile', SecurityEvent::query()->where('event', 'account.contact_changed')->sole()->context['fields']);
    }

    public function test_what_is_refused(): void
    {
        $other = User::factory()->create(['email' => 'taken@cg.gov.in', 'mobile' => '9333333333']);

        $this->actingAs($this->ao)->post(route('account.contact.start'), ['email' => 'me@gmail.com', 'mobile' => '9000000001', 'current_password' => 'password'])->assertSessionHasErrorsIn('contact', 'email');
        $this->actingAs($this->ao)->post(route('account.contact.start'), ['email' => 'taken@cg.gov.in', 'mobile' => '9000000001', 'current_password' => 'password'])->assertSessionHasErrorsIn('contact', 'email');
        $this->actingAs($this->ao)->post(route('account.contact.start'), ['email' => 'ao@cg.gov.in', 'mobile' => '9333333333', 'current_password' => 'password'])->assertSessionHasErrorsIn('contact', 'mobile');
        $this->actingAs($this->ao)->post(route('account.contact.start'), ['email' => 'ao@cg.gov.in', 'mobile' => '12345', 'current_password' => 'password'])->assertSessionHasErrorsIn('contact', 'mobile');
        $this->actingAs($this->ao)->post(route('account.contact.start'), ['email' => 'AO@cg.gov.in', 'mobile' => '9000000001', 'current_password' => 'password'])->assertSessionHasErrorsIn('contact', 'email');

        $this->assertSame(0, OtpVerification::query()->where('purpose', 'contact_change')->count());
        $this->assertSame('taken@cg.gov.in', $other->fresh()->email);
    }

    public function test_codes_without_a_change_in_progress_are_refused(): void
    {
        $this->actingAs($this->ao)->post(route('account.contact.verify'), ['email_code' => '123456', 'mobile_code' => '123456'])
            ->assertRedirect(route('account.show'))
            ->assertSessionHasErrors('email');
        $this->assertSame('ao@cg.gov.in', $this->ao->fresh()->email);
    }

    public function test_without_sms_only_the_email_is_proved_and_the_mobile_is_waived(): void
    {
        config(['dpc.otp.fixed.mobile' => false]);

        $this->actingAs($this->ao)->get(route('account.show'))->assertSee(__('profile.account.change_lead_no_sms'));
        $this->actingAs($this->ao)->post(route('account.contact.start'), ['email' => 'ao@cg.gov.in', 'mobile' => '9444444444', 'current_password' => 'password'])->assertSessionHasNoErrors();
        $this->assertSame(0, OtpVerification::query()->where('identifier', '9444444444')->count());

        $this->actingAs($this->ao)->post(route('account.contact.verify'), ['email_code' => '123456'])->assertSessionHasNoErrors();

        $ao = $this->ao->fresh();
        $this->assertSame('9444444444', $ao->mobile);
        $this->assertNull($ao->mobile_verified_at);
        $this->assertTrue($ao->hasMobileWaiver());
    }

    public function test_cancel_withdraws_the_codes(): void
    {
        $this->actingAs($this->ao)->post(route('account.contact.start'), ['email' => 'x@cg.gov.in', 'mobile' => '9000000001', 'current_password' => 'password']);
        $this->actingAs($this->ao)->post(route('account.contact.cancel'))->assertRedirect(route('account.show'));

        $this->assertSame(0, OtpVerification::query()->where('purpose', 'contact_change')->whereNull('consumed_at')->count());
        $this->actingAs($this->ao)->get(route('account.show'))->assertSee('id="account-contact-start"', false);
    }

    // ------------------------------------------------------------------
    // Current password, uniqueness, authorization and logs (2026-11-03)
    // ------------------------------------------------------------------

    public function test_the_current_password_is_required_to_change_email_or_mobile(): void
    {
        $this->actingAs($this->ao)->post(route('account.contact.start'), ['email' => 'new@cg.gov.in', 'mobile' => '9000000001'])
            ->assertSessionHasErrorsIn('contact', 'current_password');
        $this->actingAs($this->ao)->post(route('account.contact.start'), ['email' => 'new@cg.gov.in', 'mobile' => '9000000001', 'current_password' => 'Wrong@1234'])
            ->assertSessionHasErrorsIn('contact', 'current_password');

        $this->assertSame(0, OtpVerification::query()->where('purpose', 'contact_change')->count(), 'no code without the password');
        $this->assertSame('ao@cg.gov.in', $this->ao->fresh()->email);
    }

    public function test_the_password_is_changed_from_my_profile_only_with_the_current_one(): void
    {
        $this->actingAs($this->ao)->get(route('account.show'))->assertSee('id="account-password-form"', false);

        $this->actingAs($this->ao)->post(route('password.change.update'), [
            'return_to' => 'account', 'current_password' => 'Wrong@1234', 'password' => 'New@Pass2026', 'password_confirmation' => 'New@Pass2026',
        ])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('password', $this->ao->fresh()->password));

        $this->actingAs($this->ao)->post(route('password.change.update'), [
            'return_to' => 'account', 'current_password' => 'password', 'password' => 'New@Pass2026', 'password_confirmation' => 'New@Pass2026',
        ])->assertRedirect(route('account.show'));
        $this->assertTrue(Hash::check('New@Pass2026', $this->ao->fresh()->password));
    }

    public function test_an_email_in_another_case_is_still_taken(): void
    {
        User::factory()->create(['email' => 'Taken.Officer@cg.gov.in']);

        $this->actingAs($this->ao)->post(route('account.contact.start'), ['email' => 'taken.officer@CG.gov.in', 'mobile' => '9000000001', 'current_password' => 'password'])
            ->assertSessionHasErrorsIn('contact', 'email');
        $this->assertSame(0, OtpVerification::query()->where('purpose', 'contact_change')->count());
    }

    public function test_a_change_taken_by_another_account_meanwhile_is_refused_at_the_codes(): void
    {
        $this->actingAs($this->ao)->post(route('account.contact.start'), ['email' => 'race@cg.gov.in', 'mobile' => '9000000001', 'current_password' => 'password']);
        User::factory()->create(['email' => 'RACE@cg.gov.in']);

        $this->actingAs($this->ao)->post(route('account.contact.verify'), ['email_code' => '123456', 'mobile_code' => '123456'])
            ->assertSessionHasErrors('email');
        $this->assertSame('ao@cg.gov.in', $this->ao->fresh()->email);
    }

    /**
     * Only the signed-in user's own account changes: there is no user parameter, and
     * one smuggled into the form is ignored. Another account is untouched.
     */
    public function test_a_user_changes_only_their_own_account(): void
    {
        $other = User::factory()->create(['email' => 'other@cg.gov.in', 'mobile' => '9555555555']);
        $row = fn (): array => (array) DB::table('users')->where('id', $other->id)->first(['email', 'mobile', 'password', 'email_verified_at', 'mobile_verified_at', 'session_epoch']);
        $before = $row();

        $this->actingAs($this->ao)->post(route('account.contact.start'), [
            'email' => 'mine@cg.gov.in', 'mobile' => '9666666666', 'current_password' => 'password', 'user_id' => $other->id, 'id' => $other->id,
        ]);
        $this->actingAs($this->ao)->post(route('account.contact.verify'), ['email_code' => '123456', 'mobile_code' => '123456', 'user_id' => $other->id]);

        $this->assertSame('mine@cg.gov.in', $this->ao->fresh()->email);
        $this->assertSame($before, $row());

        auth()->logout();
        $this->post(route('account.contact.start'), ['email' => 'x@cg.gov.in', 'mobile' => '9777777777', 'current_password' => 'password'])->assertRedirect(route('login'));
    }

    /**
     * Neither the OTP nor a password reaches the log, the security log or the audit
     * trail.
     */
    public function test_no_password_or_code_is_logged(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        $this->actingAs($this->ao)->post(route('account.contact.start'), ['email' => 'log@cg.gov.in', 'mobile' => '9888888888', 'current_password' => 'password']);
        $this->actingAs($this->ao)->post(route('account.contact.verify'), ['email_code' => '123456', 'mobile_code' => '123456']);
        $this->actingAs($this->ao)->post(route('password.change.update'), ['current_password' => 'password', 'password' => 'New@Pass2026', 'password_confirmation' => 'New@Pass2026']);

        $everything = implode("\n", [
            ...$logged,
            DB::table('security_events')->get()->toJson(),
            DB::table('audits')->get()->toJson(),
            DB::table('activity_timelines')->get()->toJson(),
        ]);

        foreach (['123456', 'New@Pass2026', 'password'.'"'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
        $this->assertStringNotContainsString((string) $this->ao->fresh()->password, $everything, 'not even the hash');
    }
}
