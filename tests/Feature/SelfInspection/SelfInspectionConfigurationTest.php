<?php

declare(strict_types=1);

namespace Tests\Feature\SelfInspection;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Models\DpcAllotment;
use App\Domain\Cgpsc\Models\DpcSchedule;
use App\Domain\SelfInspection\Services\ObjectionDeadlineResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\SelfInspection\Concerns\BuildsInspectableCharts;
use Tests\TestCase;

/**
 * The judgement calls in M10, and the fact that each one is configuration.
 *
 * Two of them stand in for open business questions, so the ruling — when it
 * comes — is applied by editing configuration rather than code:
 *
 *  - **BD-19**, how an individual officer authenticates. The three answers are the
 *    three drivers, and the two that need data or a workflow the project does not
 *    have refuse with a structured *Pending Business Decision* rather than
 *    inventing one.
 *  - **The deadline in S51's notice.** The deck shows a date and says nothing
 *    about where it comes from. It is derived from the scheduled sitting, and
 *    **when it cannot be derived the notice omits it** rather than guessing.
 */
class SelfInspectionConfigurationTest extends TestCase
{
    use BuildsInspectableCharts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootChart();
    }

    // ------------------------------------------------------------------
    // BD-19 — the authentication driver
    // ------------------------------------------------------------------

    /**
     * The drivers that still cannot run.
     *
     * **`otp` left this list on 2026-09-07.** It was blocked on one thing — no
     * officer snapshot carried a contact channel — and the Seniority List now
     * carries a mobile number, so it is implemented and is the default.
     * `SelfInspectionOtpTest` covers it.
     *
     * `account` remains: it needs somebody to create and issue an Individual
     * account, which no screen in either deck does, and inventing that workflow
     * would decide who owns an officer's credentials.
     *
     * @return list<array{string}>
     */
    public static function unimplementedDrivers(): array
    {
        return [['account']];
    }

    #[DataProvider('unimplementedDrivers')]
    public function test_an_unavailable_driver_refuses_with_a_named_business_decision(string $driver): void
    {
        Config::set('dpc.self_inspection.authentication.driver', $driver);

        $this->get(route('self-inspection.landing'))
            ->assertOk()
            ->assertSee('Pending Business Decision (BD-19)')
            // The refusal explains itself in business language, not as an error.
            ->assertSee('No authentication mechanism was invented, and no officer data was shown.');

        // And the form is not offered, so nobody types three facts into a screen
        // that was always going to refuse them.
        $this->get(route('self-inspection.identify'))
            ->assertOk()
            ->assertSee('not available')
            ->assertDontSee('Date of Joining Promotion To Post');
    }

    #[DataProvider('unimplementedDrivers')]
    public function test_an_unavailable_driver_cannot_be_bypassed_by_posting_directly(string $driver): void
    {
        Config::set('dpc.self_inspection.authentication.driver', $driver);

        $this->post(route('self-inspection.identify.store'), $this->correctFactors())
            ->assertRedirect(route('self-inspection.identify'));

        $this->get(route('self-inspection.show'))->assertRedirect(route('self-inspection.identify'));
    }

    /** An unrecognised driver falls back to the working one rather than failing. */
    public function test_an_unrecognised_driver_falls_back_to_identity_factors(): void
    {
        Config::set('dpc.self_inspection.authentication.driver', 'something-nobody-implemented');

        $this->post(route('self-inspection.identify.store'), $this->correctFactors())
            ->assertRedirect(route('self-inspection.show'));
    }

    /**
     * An empty factor list would authenticate everybody, so it is the one
     * configuration value that cannot be honoured as written.
     */
    public function test_an_empty_factor_list_falls_back_rather_than_admitting_everyone(): void
    {
        Config::set('dpc.self_inspection.authentication.factors', []);

        $this->post(route('self-inspection.identify.store'), [])
            ->assertSessionHasErrors(['name', 'date_of_posting_on_promotion_to_post', 'dob']);
    }

    // ------------------------------------------------------------------
    // The deadline in S51's notice
    // ------------------------------------------------------------------

    public function test_the_deadline_is_derived_from_the_scheduled_sitting(): void
    {
        $this->schedule('2026-09-10');

        Config::set('dpc.self_inspection.objection_deadline.days_before_sitting', 15);

        $deadline = app(ObjectionDeadlineResolver::class)->resolve($this->proposal);

        $this->assertNotNull($deadline);
        $this->assertSame('2026-08-26', $deadline->format('Y-m-d'));

        $this->identify();
        $this->get(route('self-inspection.show'))
            ->assertOk()
            ->assertSee('26 August 2026')
            ->assertSee('नस्तीबद्ध', escape: false);
    }

    public function test_the_number_of_days_is_configurable(): void
    {
        $this->schedule('2026-09-10');

        Config::set('dpc.self_inspection.objection_deadline.days_before_sitting', 30);

        $this->assertSame(
            '2026-08-11',
            app(ObjectionDeadlineResolver::class)->resolve($this->proposal)?->format('Y-m-d'),
        );
    }

    /**
     * **The important one.** No sitting means no derivable deadline, and the
     * notice is shown *without* a date rather than with an invented one — while
     * still telling the officer to raise a correction with the department.
     */
    public function test_no_scheduled_sitting_produces_a_notice_with_no_date(): void
    {
        $this->assertNull(app(ObjectionDeadlineResolver::class)->resolve($this->proposal));

        $this->identify();

        $this->get(route('self-inspection.show'))
            ->assertOk()
            // The route to a correction is stated whether or not a date can be
            // derived. Asserted in the screen's own language: this string carried
            // both languages in one value until the catalogue was split on
            // 2026-08-25, and asserting the Hindi half on an English screen was
            // only ever true by accident of that.
            ->assertSee('by attending your department in person')
            ->assertSee('has not been scheduled yet')
            // And no invented deadline.
            ->assertDontSee('on or before');
    }

    public function test_the_basis_can_be_switched_off_entirely(): void
    {
        $this->schedule('2026-09-10');

        Config::set('dpc.self_inspection.objection_deadline.basis', 'none');

        $this->assertNull(app(ObjectionDeadlineResolver::class)->resolve($this->proposal));
    }

    /** A typo in configuration must not take the screen down. */
    public function test_an_unrecognised_basis_is_treated_as_none(): void
    {
        $this->schedule('2026-09-10');

        Config::set('dpc.self_inspection.objection_deadline.basis', 'whatever_someone_typed');

        $this->assertNull(app(ObjectionDeadlineResolver::class)->resolve($this->proposal));

        $this->identify();
        $this->get(route('self-inspection.show'))->assertOk();
    }

    /**
     * A passed deadline is still shown, and said to have passed.
     *
     * Suppressing it would leave the officer thinking they still had time, which
     * is the opposite of what S51's notice is for.
     */
    public function test_a_deadline_already_passed_is_shown_and_flagged(): void
    {
        $this->schedule(now()->subDays(5)->format('Y-m-d'));

        $this->identify();

        $this->get(route('self-inspection.show'))
            ->assertOk()
            ->assertSee('This date has passed');
    }

    /**
     * A re-scheduled DPC yields the date now in force, not the superseded one —
     * the same rule M6 applies everywhere else.
     */
    public function test_a_superseded_schedule_is_ignored(): void
    {
        $allotment = $this->allotment();

        $old = DpcSchedule::query()->create([
            'dpc_allotment_id' => $allotment->id, 'dpc_date' => '2026-09-10',
            'dpc_time' => '11:00', 'scheduled_at' => now(),
        ]);

        $new = DpcSchedule::query()->create([
            'dpc_allotment_id' => $allotment->id, 'dpc_date' => '2026-10-20',
            'dpc_time' => '11:00', 'scheduled_at' => now(),
        ]);

        $old->forceFill(['superseded_by_id' => $new->id])->save();

        $this->assertSame(
            '2026-10-20',
            app(ObjectionDeadlineResolver::class)->sittingDate($this->proposal)?->format('Y-m-d'),
        );
    }

    // ------------------------------------------------------------------
    // Rate limiting
    // ------------------------------------------------------------------

    /**
     * The strictest limiter in the application, on the only endpoint an
     * unauthenticated caller can reach with officer data behind it.
     */
    public function test_repeated_failures_are_throttled(): void
    {
        RateLimiter::clear('self-inspection|anil kumar verma|127.0.0.1');

        Config::set('dpc.self_inspection.rate_limit.max_attempts', 3);
        Config::set('dpc.self_inspection.rate_limit.decay_seconds', 300);

        $wrong = $this->correctFactors();
        $wrong['dob'] = '1970-01-01';

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post(route('self-inspection.identify.store'), $wrong)
                ->assertSessionHasErrors('self_inspection');
        }

        // The fourth is refused by the limiter, and — crucially — the *correct*
        // answers are refused too: the block is on the identity being guessed at,
        // not on the wrongness of a particular attempt.
        $this->post(route('self-inspection.identify.store'), $this->correctFactors())
            ->assertSessionHasErrors('self_inspection');

        $this->get(route('self-inspection.show'))->assertRedirect(route('self-inspection.identify'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function identify(): void
    {
        $this->post(route('self-inspection.identify.store'), $this->correctFactors())
            ->assertRedirect(route('self-inspection.show'));
    }

    private function allotment(): DpcAllotment
    {
        $member = User::factory()->create(['name' => 'Member One']);

        return DpcAllotment::query()->create([
            'dpc_proposal_id' => $this->proposal->id,
            'allotted_to' => $member->id,
            'allotted_at' => now(),
        ]);
    }

    private function schedule(string $date): DpcSchedule
    {
        return DpcSchedule::query()->create([
            'dpc_allotment_id' => $this->allotment()->id,
            'dpc_date' => Carbon::parse($date)->format('Y-m-d'),
            'dpc_time' => '11:00',
            'scheduled_at' => now(),
        ]);
    }
}
