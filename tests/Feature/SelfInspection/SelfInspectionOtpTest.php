<?php

declare(strict_types=1);

namespace Tests\Feature\SelfInspection;

use App\Domain\Authentication\Models\OtpVerification;
use App\Domain\SelfInspection\Models\SelfInspectionAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\SelfInspection\Concerns\BuildsInspectableCharts;
use Tests\TestCase;

/**
 * Self-Inspection by mobile number and one-time code (business instruction,
 * 2026-09-07).
 *
 * > *Self-Inspection is still not with mobile number and OTP — how did this get
 * > left out? When will you do it? The candidate should be able to see their own
 * > record.*
 *
 * The `otp` driver had been recorded as unbuildable for one reason: *"none of the
 * three officer-snapshot tables carries a contact channel"*. The Seniority List
 * gained a mobile number the same day, which is what unblocked it. It is now the
 * default, because proving you hold the number your department recorded is a
 * stronger claim than reciting three facts that circulate on the seniority list
 * itself.
 *
 * **The test that matters most is the enumeration one.** A screen that says *"no
 * such officer"* is a lookup service: feed it numbers and it tells you which belong
 * to somebody under consideration for promotion.
 */
final class SelfInspectionOtpTest extends TestCase
{
    use BuildsInspectableCharts;
    use RefreshDatabase;

    private const MOBILE = '9425012345';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootChart();

        // `bootChart()` pins the factor driver; this class is about the other one.
        config(['dpc.self_inspection.authentication.driver' => 'otp']);

        $this->row->forceFill(['mobile' => self::MOBILE])->save();
    }

    /**
     * The demo code the local environment issues, so a test reads like the officer's
     * own experience rather than reaching into the OTP table.
     */
    private function demoCode(): string
    {
        return (string) config('dpc.otp.demo_code', '123456');
    }

    private function requestCode(string $mobile): TestResponse
    {
        return $this->post(route('self-inspection.identify.store'), ['mobile' => $mobile]);
    }

    private function enterCode(string $code): TestResponse
    {
        return $this->post(route('self-inspection.identify.store'), ['code' => $code]);
    }

    // ------------------------------------------------------------------ screen

    public function test_the_screen_asks_for_a_mobile_number(): void
    {
        $this->get(route('self-inspection.identify'))
            ->assertOk()
            ->assertSee(__('selfinspection.otp.mobile_label'))
            ->assertSee('name="mobile"', false)
            // The factor boxes belong to the other driver and must not be here.
            ->assertDontSee('name="dob"', false);
    }

    public function test_after_asking_for_a_code_the_screen_asks_for_the_code(): void
    {
        $this->requestCode(self::MOBILE);

        $this->get(route('self-inspection.identify'))
            ->assertOk()
            ->assertSee(__('selfinspection.otp.code_label'))
            ->assertSee('name="code"', false);
    }

    // ------------------------------------------------------------ the happy path

    public function test_an_officer_reaches_their_own_record_with_the_code(): void
    {
        $this->requestCode(self::MOBILE)->assertSessionHasNoErrors();

        $this->enterCode($this->demoCode())
            ->assertRedirect(route('self-inspection.show'));

        $this->get(route('self-inspection.show'))
            ->assertOk()
            ->assertSee($this->row->name);
    }

    /**
     * A department that recorded `+91 94250-12345` and an officer who types
     * `9425012345` are the same person. A match that depended on punctuation would
     * fail for the officer rather than for an attacker.
     */
    public function test_the_number_matches_however_the_department_recorded_it(): void
    {
        $this->row->forceFill(['mobile' => '+91 94250-12345'])->save();

        $this->requestCode(self::MOBILE);

        $this->enterCode($this->demoCode())->assertRedirect(route('self-inspection.show'));
    }

    // -------------------------------------------------------------- enumeration

    /**
     * **A number on no list is answered exactly as one on a list.**
     *
     * Otherwise this screen is a way to discover who is up for promotion: try
     * numbers, and the ones that come back differently belong to candidates.
     */
    public function test_an_unknown_number_is_answered_the_same_way(): void
    {
        $known = $this->requestCode(self::MOBILE);
        $unknown = $this->requestCode('9000000001');

        $known->assertSessionHasNoErrors();
        $unknown->assertSessionHasNoErrors();
        $this->assertSame($known->headers->get('location'), $unknown->headers->get('location'));

        // No code was issued for the unknown number, which is what makes the
        // identical answer safe rather than merely quiet.
        $this->assertSame(1, OtpVerification::query()->count());
        $this->assertSame(self::MOBILE, OtpVerification::query()->sole()->identifier);
    }

    /**
     * The attempt is still recorded. Somebody walking a range of numbers past this
     * screen leaves a trail, even though the screen tells them nothing.
     */
    public function test_an_unknown_number_is_recorded_as_a_failed_attempt(): void
    {
        $this->requestCode('9000000001');

        $access = SelfInspectionAccess::query()->latest('id')->sole();

        $this->assertFalse($access->matched);
        $this->assertNull($access->dpc_evaluation_row_id);
    }

    public function test_a_code_for_an_unknown_number_opens_nothing(): void
    {
        $this->requestCode('9000000001');

        // The demo code is not a master key: nothing was issued for this number.
        $this->enterCode($this->demoCode())->assertSessionHasErrors('self_inspection');

        $this->get(route('self-inspection.show'))->assertRedirect(route('self-inspection.identify'));
    }

    // ----------------------------------------------------------------- refusals

    public function test_a_wrong_code_is_refused(): void
    {
        $this->requestCode(self::MOBILE);

        $this->enterCode('000000')->assertSessionHasErrors('self_inspection');

        $this->get(route('self-inspection.show'))->assertRedirect(route('self-inspection.identify'));
    }

    public function test_a_malformed_mobile_is_refused_before_anything_is_sent(): void
    {
        $this->requestCode('12345')->assertSessionHasErrors('mobile');

        $this->assertSame(0, OtpVerification::query()->count());
    }

    public function test_the_code_box_cannot_be_reached_without_asking_first(): void
    {
        // No number in the session, so there is nothing to verify against. Starting
        // again is the honest answer rather than guessing which number was meant.
        $this->enterCode($this->demoCode())->assertRedirect(route('self-inspection.identify'));
    }

    /**
     * **The code is verified against the number it was sent to**, which is why the
     * number lives in the session rather than in a hidden field. A field would let a
     * caller request a code for their own number and verify it against somebody
     * else's.
     */
    public function test_using_a_different_number_clears_the_pending_one(): void
    {
        $this->requestCode(self::MOBILE);

        $this->post(route('self-inspection.identify.store'), ['restart' => '1'])
            ->assertRedirect(route('self-inspection.identify'));

        $this->get(route('self-inspection.identify'))
            ->assertOk()
            ->assertSee('name="mobile"', false)
            ->assertDontSee('name="code"', false);
    }

    /**
     * A verified code for a number that has since left every list opens nothing:
     * the inspectable rows are re-read at verification, not remembered from when the
     * code was sent.
     */
    public function test_a_row_that_closes_between_the_code_and_the_check_opens_nothing(): void
    {
        $this->requestCode(self::MOBILE);

        $this->row->forceFill(['mobile' => null])->save();

        $this->enterCode($this->demoCode())->assertSessionHasErrors('self_inspection');
    }
}
