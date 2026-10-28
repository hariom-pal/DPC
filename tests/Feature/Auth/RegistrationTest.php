<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Department\Models\DepartmentInvitation;
use App\Domain\Master\Models\District;
use App\Enums\DepartmentStatus;
use App\Enums\Role;
use App\Integrations\Contracts\SmsSender;
use Database\Seeders\DesignationSeeder;
use Database\Seeders\DistrictSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\ReadsSentMail;
use Tests\Support\RecordingSmsSender;
use Tests\TestCase;

/**
 * Department Admin registration by invitation (2026-10-26; DigiLocker 2026-10-27; department + e-mail 2026-10-28).
 */
class RegistrationTest extends TestCase
{
    use ReadsSentMail;
    use RefreshDatabase;

    private User $portalAdmin;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DistrictSeeder::class, DesignationSeeder::class]);

        $this->portalAdmin = User::factory()->create();
        $this->portalAdmin->assignRole(Role::Admin->value);

        $this->department = Department::query()->create([
            'code' => 'PHE', 'name' => 'Public Health Engineering Department', 'status' => DepartmentStatus::Unregistered->value,
            'district_id' => District::query()->where('name_en', 'Raipur')->value('id'),
            'place' => 'Nava Raipur', 'pin' => '492002', 'email' => 'phe.dpc@cg.gov.in',
        ]);

        // Both OTPs are always asked (2026-10-28); the mobile is proved with the
        // fixed code here, as on the local environment.
        config(['dpc.otp.fixed.mobile' => true]);
    }

    private function invite(?Department $department = null): TestResponse
    {
        // The department and its own e-mail (2026-10-28).
        $department ??= $this->department;

        return $this->actingAs($this->portalAdmin)->post(route('admin.department-invitations.store'), [
            'department_id' => $department->id, 'email' => $department->email,
        ]);
    }

    private function tokenFromMail(): string
    {
        $mail = collect($this->sentMail())->last(fn (array $m): bool => str_contains($m['body'], '/register/'));
        $this->assertNotNull($mail, 'No invitation e-mail was sent.');
        preg_match('#/register/([A-Za-z0-9]{64})#', $mail['body'], $match);

        return $match[1];
    }

    /**
     * @param  array<string, mixed>  $codes
     */
    private function register(string $token, array $codes = [], string $mobile = '9000000401'): TestResponse
    {
        auth()->logout();
        $this->travel(2)->minutes(); // past the registration and OTP rate limits between attempts
        $this->post(route('register.invitation.start', ['token' => $token]), ['mobile' => $mobile])
            ->assertRedirect(route('register.invitation.verify', ['token' => $token]));

        return $this->post(route('register.invitation.verify.store', ['token' => $token]), array_replace(['email_code' => '123456', 'mobile_code' => '123456'], $codes));
    }

    private function finish(string $token): TestResponse
    {
        $start = $this->post(route('register.invitation.identity.start', ['token' => $token]));
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
        $callback = $this->post(route('identity.simulator.submit'), [
            'state' => (string) ($query['state'] ?? ''), 'decision' => 'allow',
            'name' => 'Shri D. Admin', 'date_of_birth' => '1978-01-20', 'gender' => 'M', 'aadhaar_verified' => '1',
        ]);
        $this->get((string) $callback->headers->get('Location'))->assertRedirect(route('register.invitation.password', ['token' => $token]));

        return $this->post(route('register.invitation.complete', ['token' => $token]), ['password' => 'Dept@Admin2026', 'password_confirmation' => 'Dept@Admin2026']);
    }

    public function test_register_without_an_invitation_offers_nothing(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee(__('invitations.register.by_invitation'))
            ->assertDontSee('Public Health Engineering Department')
            ->assertDontSee('<form', false);
    }

    public function test_only_the_portal_super_admin_invites(): void
    {
        $officer = User::factory()->create();
        $officer->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($officer)->get(route('admin.department-invitations.index'))->assertForbidden();
        $this->actingAs($officer)->post(route('admin.department-invitations.store'), [
            'department_id' => $this->department->id, 'email' => 'phe.dpc@cg.gov.in',
        ])->assertForbidden();
        $this->assertSame(0, DepartmentInvitation::query()->count());
    }

    public function test_the_department_admin_registers_with_both_otps_and_digilocker(): void
    {
        $this->invite()->assertSessionHasNoErrors();
        $invitation = DepartmentInvitation::query()->sole();
        $token = $this->tokenFromMail();
        $this->assertSame(DepartmentInvitation::hashToken($token), $invitation->token_hash, 'Only the hash is stored.');

        $this->get(route('register.invitation', ['token' => $token]))
            ->assertOk()->assertSee('Public Health Engineering Department')->assertSee('phe.dpc@cg.gov.in')->assertDontSee('waive');

        // The mobile code is required.
        $this->register($token, ['mobile_code' => ''])->assertSessionHasErrors('mobile_code');
        $this->register($token)->assertRedirect(route('register.invitation.identity', ['token' => $token]));
        $this->finish($token)->assertRedirect(route('login'));

        $admin = User::query()->where('email', 'phe.dpc@cg.gov.in')->sole();
        $this->assertTrue($admin->hasRole(Role::DepartmentAdmin->value));
        $this->assertSame($this->department->id, $admin->department_id);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertNotNull($admin->mobile_verified_at, 'Always proved by OTP.');
        $this->assertNull($admin->mobile_verification_waived_at);
        $this->assertFalse($admin->must_change_password, 'The officer chose the password.');
        $this->assertTrue(Hash::check('Dept@Admin2026', $admin->password));
        $this->assertSame(['Shri D. Admin', '1978-01-20', 'M'], [$admin->name, $admin->date_of_birth?->toDateString(), $admin->gender], 'From DigiLocker.');
        $this->assertSame(DepartmentStatus::Registered, $this->department->fresh()->status);
        $this->assertNotNull($invitation->fresh()->accepted_at);

        $this->post(route('login.store'), ['login' => $admin->email, 'password' => 'Dept@Admin2026'])->assertRedirect(route('verify.show'));
        $this->passSignInChallenge()->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_the_mobile_otp_is_sent_and_checked_when_the_sms_gateway_is_available(): void
    {
        config(['features.sms_gateway' => true, 'dpc.otp.fixed.mobile' => false]);
        RecordingSmsSender::reset();
        $this->app->bind(SmsSender::class, RecordingSmsSender::class);

        $this->invite()->assertSessionHasNoErrors();
        $token = $this->tokenFromMail();

        // A wrong mobile code beside a right e-mail code consumes neither (2026-10-27).
        $this->register($token, ['mobile_code' => '000000'])->assertSessionHasErrors(['email_code' => __('authui.otp.invalid_pair')]);
        $this->assertContains('9000000401', RecordingSmsSender::mobiles());

        $this->post(route('register.invitation.verify.store', ['token' => $token]), ['email_code' => '123456', 'mobile_code' => '123456'])
            ->assertRedirect(route('register.invitation.identity', ['token' => $token]));
    }

    public function test_without_any_way_to_prove_the_mobile_registration_stops(): void
    {
        config(['features.sms_gateway' => false, 'dpc.otp.fixed.mobile' => false]);
        $this->invite()->assertSessionHasNoErrors();
        $token = $this->tokenFromMail();

        auth()->logout();
        $this->post(route('register.invitation.start', ['token' => $token]), ['mobile' => '9000000401'])
            ->assertSessionHasErrors(['mobile' => __('invitations.errors.mobile_unverifiable')]);
    }

    public function test_a_wrong_email_otp_creates_nothing(): void
    {
        $this->invite()->assertSessionHasNoErrors();
        $token = $this->tokenFromMail();

        $this->register($token, ['email_code' => '000000'])->assertSessionHasErrors('email_code');
        $this->get(route('register.invitation.identity', ['token' => $token]))->assertRedirect(route('register.invitation', ['token' => $token]));
        $this->assertFalse(User::query()->where('email', 'phe.dpc@cg.gov.in')->exists());
        $this->assertSame(DepartmentStatus::Unregistered, $this->department->fresh()->status);
    }

    public function test_a_used_expired_or_revoked_link_cannot_register(): void
    {
        $this->invite()->assertSessionHasNoErrors();
        $token = $this->tokenFromMail();
        $this->register($token);
        $this->finish($token)->assertRedirect(route('login'));

        // Used.
        $this->get(route('register.invitation', ['token' => $token]))->assertOk()->assertSee('id="invitation-invalid"', false);
        $this->post(route('register.invitation.start', ['token' => $token]), ['mobile' => '9000000499'])->assertNotFound();

        // Expired.
        $this->flushSentMail();
        $this->invite()->assertOk();
        $second = $this->tokenFromMail();
        $this->travel((int) config('dpc.accounts.invitation_ttl_hours') + 1)->hours();
        auth()->logout();
        $this->get(route('register.invitation', ['token' => $second]))->assertSee('id="invitation-invalid"', false);

        // Revoked (once expired, a new invitation may be sent).
        $this->flushSentMail();
        $this->invite()->assertOk();
        $third = $this->tokenFromMail();
        $this->actingAs($this->portalAdmin)->post(route('admin.department-invitations.revoke', DepartmentInvitation::query()->latest('id')->firstOrFail()));
        auth()->logout();
        $this->get(route('register.invitation', ['token' => $third]))->assertSee('id="invitation-invalid"', false);

        // A made-up token.
        $this->get(route('register.invitation', ['token' => str_repeat('a', 64)]))->assertSee('id="invitation-invalid"', false);
    }

    public function test_an_incomplete_department_is_refused(): void
    {
        $this->department->forceFill(['pin' => null])->save();
        $this->invite()->assertSessionHasErrors(['email' => __('invitations.errors.department_incomplete')]);
        $this->assertSame(0, DepartmentInvitation::query()->count());
    }

    public function test_resend_replaces_the_link(): void
    {
        $this->invite()->assertSessionHasNoErrors();
        $first = $this->tokenFromMail();

        $this->actingAs($this->portalAdmin)->post(route('admin.department-invitations.resend', DepartmentInvitation::query()->sole()))->assertSessionHasNoErrors();
        $second = $this->tokenFromMail();

        $this->assertNotSame($first, $second);
        auth()->logout();
        $this->get(route('register.invitation', ['token' => $first]))->assertSee('id="invitation-invalid"', false);
        $this->get(route('register.invitation', ['token' => $second]))->assertDontSee('id="invitation-invalid"', false);
    }

    /**
     * A mail failure is recorded and shown, and no longer blocks the invitation:
     * the link is on screen for the Administrator to hand over (2026-10-26).
     */
    public function test_a_mail_server_failure_is_reported_and_the_link_is_still_shown(): void
    {
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('SMTP refused'));

        $response = $this->invite()->assertOk()->assertSessionHasNoErrors();
        $response->assertSee('id="invitation-not-mailed"', false)->assertDontSee('id="invitation-mailed"', false);

        $invitation = DepartmentInvitation::query()->sole();
        $this->assertNotNull($invitation->delivery_error);
        $this->assertNull($invitation->last_sent_at);
        $this->assertNotNull(DepartmentInvitation::findByToken($this->tokenFromPage($response)));
    }

    /**
     * The link is shown once, as the direct response: not cached, not flashed, not
     * in the timeline — and it is the link that registers.
     */
    public function test_the_link_is_shown_once_to_the_administrator_and_works(): void
    {
        $response = $this->invite()->assertOk()->assertSee('id="invitation-mailed"', false);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $token = $this->tokenFromPage($response);
        $this->assertSame($this->tokenFromMail(), $token, 'The page and the e-mail carry the same link.');
        $this->assertNull(session('link'));
        $this->assertSame(0, DB::table('activity_timelines')->where('description', 'like', '%'.$token.'%')->count());

        $again = $this->actingAs($this->portalAdmin)->post(route('admin.department-invitations.resend', DepartmentInvitation::query()->sole()))
            ->assertOk()->assertSee(__('invitations.issued.previous_invalid'));
        $this->assertNotSame($token, $this->tokenFromPage($again));

        auth()->logout();
        $this->get(route('register.invitation', ['token' => $this->tokenFromPage($again)]))->assertDontSee('id="invitation-invalid"', false);
    }

    private function tokenFromPage(TestResponse $response): string
    {
        preg_match('#id="invitation-link">[^<]*/register/([A-Za-z0-9]{64})<#', (string) $response->getContent(), $match);
        $this->assertNotEmpty($match, 'The registration link is on the page.');

        return $match[1];
    }
}
