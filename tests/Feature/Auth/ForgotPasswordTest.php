<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Authentication\Models\OtpVerification;
use App\Domain\Authentication\Models\SecurityEvent;
use App\Domain\Authentication\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Forgot password (2026-11-03): OTP on the account's e-mail and mobile, then a new
 * password; the same answer whether or not an account matched. The fixed test code
 * is 123456 for both channels (phpunit.xml).
 */
class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'New@Pass2026';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
        $this->user = User::factory()->create(['email' => 'officer@cg.gov.in', 'mobile' => '9000000011']);
    }

    private function reset(array $overrides = []): TestResponse
    {
        return $this->post(route('password.forgot.update'), $overrides + [
            'email_code' => '123456', 'mobile_code' => '123456',
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ]);
    }

    public function test_the_login_page_offers_it(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(route('password.forgot'), false);
        $this->get(route('password.forgot'))->assertOk()->assertSee('id="forgot-password"', false);
        // Nothing to verify before a request.
        $this->get(route('password.forgot.verify'))->assertRedirect(route('password.forgot'));
    }

    public function test_the_owner_sets_a_new_password_with_both_codes(): void
    {
        $epoch = (int) $this->user->session_epoch;

        $this->post(route('password.forgot.send'), ['login' => 'Officer@cg.gov.in'])
            ->assertRedirect(route('password.forgot.verify'))->assertSessionHas('status', __('authui.forgot.codes_sent'));
        $this->assertSame(1, OtpVerification::query()->where('identifier', 'officer@cg.gov.in')->where('purpose', 'password_reset')->count());
        $this->assertSame(1, OtpVerification::query()->where('identifier', '9000000011')->where('purpose', 'password_reset')->count());
        $this->get(route('password.forgot.verify'))->assertOk()->assertSee('mobile_code', false);

        // A wrong mobile code, and a weak password, change nothing.
        $this->reset(['mobile_code' => '000000'])->assertSessionHasErrors('email_code');
        $this->reset(['password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));

        $this->reset()->assertRedirect(route('login'))->assertSessionHas('status', __('authui.forgot.done'));

        $user = $this->user->fresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertGreaterThan($epoch, (int) $user->session_epoch, 'every other session ends');
        $this->assertSame(1, SecurityEvent::query()->where('event', 'password.reset_by_owner')->where('user_id', $user->id)->count());

        // Used once.
        $this->get(route('password.forgot.verify'))->assertRedirect(route('password.forgot'));
    }

    public function test_by_mobile_too(): void
    {
        $this->post(route('password.forgot.send'), ['login' => '9000000011'])->assertRedirect(route('password.forgot.verify'));
        $this->reset()->assertRedirect(route('login'));
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $this->user->fresh()->password));
    }

    public function test_an_unknown_or_inactive_account_gets_the_same_answer_and_no_code(): void
    {
        $inactive = User::factory()->create(['email' => 'gone@cg.gov.in', 'is_active' => false]);

        foreach (['nobody@cg.gov.in', 'gone@cg.gov.in', '9999999999', 'E-12'] as $login) {
            $this->post(route('password.forgot.send'), ['login' => $login])
                ->assertRedirect(route('password.forgot.verify'))->assertSessionHas('status', __('authui.forgot.codes_sent'));
            $this->reset()->assertSessionHasErrors('email_code');
        }

        $this->assertSame(0, OtpVerification::query()->where('purpose', 'password_reset')->count());
        $this->assertTrue(Hash::check('password', $inactive->fresh()->password));
    }

    public function test_without_sms_the_email_code_alone_is_asked(): void
    {
        config(['dpc.otp.fixed.mobile' => false]);

        $this->post(route('password.forgot.send'), ['login' => 'officer@cg.gov.in']);
        $this->assertSame(0, OtpVerification::query()->where('identifier', '9000000011')->count());
        $this->get(route('password.forgot.verify'))->assertDontSee('name="mobile_code"', false);

        $this->reset(['mobile_code' => ''])->assertRedirect(route('login'));
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $this->user->fresh()->password));
    }

    public function test_an_expired_request_starts_again(): void
    {
        $this->post(route('password.forgot.send'), ['login' => 'officer@cg.gov.in']);
        $this->travel(16)->minutes();

        $this->reset()->assertRedirect(route('password.forgot'));
        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    /** The codes live five minutes (dpc.otp.ttl_seconds), even inside a live request. */
    public function test_an_expired_code_is_refused(): void
    {
        $this->post(route('password.forgot.send'), ['login' => 'officer@cg.gov.in']);
        $this->travel((int) config('dpc.otp.ttl_seconds') + 60)->seconds();

        $this->reset()->assertSessionHasErrors('email_code');
        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    /** A code works once: after a reset the same codes do not reset again. */
    public function test_a_code_is_single_use(): void
    {
        $this->post(route('password.forgot.send'), ['login' => 'officer@cg.gov.in']);
        $this->reset()->assertRedirect(route('login'));
        $this->assertSame(0, OtpVerification::query()->where('purpose', 'password_reset')->whereNull('consumed_at')->count());

        // Replaying the same form changes nothing: the request and its codes are spent.
        $this->reset(['password' => 'Other@Pass2026', 'password_confirmation' => 'Other@Pass2026'])
            ->assertRedirect(route('password.forgot'))->assertSessionHasErrors('login');
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $this->user->fresh()->password));
    }

    public function test_requests_are_rate_limited(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->post(route('password.forgot.send'), ['login' => "nobody{$i}@cg.gov.in"])->assertRedirect();
        }

        $this->post(route('password.forgot.send'), ['login' => 'officer@cg.gov.in'])->assertStatus(429);
        $this->assertSame(0, OtpVerification::query()->where('purpose', 'password_reset')->count());
    }

    public function test_no_password_or_code_is_logged_and_other_accounts_are_untouched(): void
    {
        $other = User::factory()->create(['email' => 'other@cg.gov.in']);
        $otherRow = (array) DB::table('users')->where('id', $other->id)->first();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        $this->post(route('password.forgot.send'), ['login' => 'officer@cg.gov.in']);
        $this->reset()->assertRedirect(route('login'));

        $everything = implode("\n", [...$logged, DB::table('security_events')->get()->toJson(), DB::table('audits')->get()->toJson()]);
        foreach (['123456', self::NEW_PASSWORD, (string) $this->user->fresh()->password] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
        $this->assertSame($otherRow, (array) DB::table('users')->where('id', $other->id)->first());
    }
}
