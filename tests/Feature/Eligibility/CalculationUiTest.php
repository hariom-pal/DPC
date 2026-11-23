<?php

declare(strict_types=1);

namespace Tests\Feature\Eligibility;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\PreviousDpcBasis;
use App\Domain\Dpc\Enums\ProposalVerificationItem;
use App\Domain\Dpc\Models\DpcCommitteeDecision;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcCommitteeService;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\ProposalVerificationService;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Eligibility\Services\DpcCalculationService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;
use App\Domain\Proceeding\Models\DpcCandidateReview;
use App\Domain\Proceeding\Services\CalculationGate;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AgreesAsCommittee;
use Tests\Concerns\CompletesDpcProposals;
use Tests\Concerns\RaisesDpcProposals;
use Tests\Concerns\SanctionsVacancies;
use Tests\Concerns\SeatsDpcCommittees;
use Tests\Concerns\SeedsEvaluationCharts;
use Tests\Concerns\UsesPptPromotionRules;
use Tests\TestCase;

/**
 * CALCULATE wiring, calculation history, Main/Waiting list UI and Explain
 * Result UI (PPT S17/S18/S33) — M5 Phase 4.
 */
class CalculationUiTest extends TestCase
{
    use AgreesAsCommittee;
    use CompletesDpcProposals;
    use RaisesDpcProposals;
    use RefreshDatabase;
    use SanctionsVacancies;
    use SeatsDpcCommittees;
    use SeedsEvaluationCharts;
    use UsesPptPromotionRules;

    private Department $department;

    private User $ao;

    private User $ah;

    private DpcProposal $proposal;

    private ReservationCategory $ur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);

        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();

        $this->ah = User::factory()->assignedTo($this->department->id, Role::AdministrativeHead)->create();

        $postFrom = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $postTo = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);
        $this->ur = ReservationCategory::query()->where('code', 'UR')->firstOrFail();

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.store'),
            $this->dpcStorePayload($this->sanctioningPreDpc($postFrom->id, $postTo->id)),
        );
        $this->proposal = DpcProposal::query()->latest('id')->firstOrFail();
        // The calculation is the DPC Committee's alone (2026-11-16): the account these
        // tests calculate as is seated on the committee.
        $this->seatAccountOnCommittee($this->proposal, $this->ao);
    }

    private function seedChart(bool $withCommittee = true): void
    {
        $service = app(DpcProposalService::class);

        // Sanctioned 1 against 1 classified vacancy and no working officers, so
        // `sanctioned − working` equals the breakdown (vacancy directive,
        // 2026-08-22) and the proposal can pass the submission gate. **The
        // engine's total vacancy is unchanged at 1** — it sums the vacancy rows,
        // not the sanctioned strength — so every scoring expectation below still
        // holds.
        $this->sanctionVacancies($this->proposal, 1, [[
            'vacancy_type_id' => VacancyType::query()->orderBy('id')->firstOrFail()->id,
            'reservation_category_id' => $this->ur->id,
            'count' => 1,
        ]]);

        $dpcYear = (int) ($this->proposal->dpc_year ?: $this->proposal->proposal_date->year);
        $officer = fn (int $sn, string $name, array $grades): array => [
            'sn' => $sn,
            'name' => $name,
            'dob' => '1964-03-20',
            'reservation_category_id' => $this->ur->id,
            'cr_grades' => array_map(
                static fn (int $y, string $g): array => ['year' => $y, 'grade' => $g],
                array_keys($grades),
                array_values($grades),
            ),
            // Both records on every CR year. Empty until the change register of
            // 2026-08-27: item 13 rejects a candidate whose Property Return is
            // missing from the mandatory window and item 15 holds one whose Loyalty
            // Certificate is, so a screen test with neither would be rendering a
            // page full of rejections rather than the two lists it means to assert.
            'loyalty_flags' => array_map(
                static fn (int $y): array => ['year' => $y, 'flag' => true],
                array_keys($grades),
            ),
            // …and every year of the Property window, all mandatory since 2026-11-22.
            'property_flags' => array_map(
                static fn (int $y): array => ['year' => $y, 'flag' => true],
                array_values(array_unique([...array_keys($grades), ...range($dpcYear - 1, $dpcYear - 5)])),
            ),
        ];

        // Seniority List first, chart projected from it (business directive,
        // 2026-08-22). Five officers, not three: the Seniority List has to match
        // the Zone of Consideration, which one vacancy sizes at 5. The two extra
        // officers score the same as US3 and change no expectation below.
        $this->seedSeniorityAndChart($this->proposal, [
            $officer(1, 'US1', [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']),      // 15 EL
            $officer(2, 'US2', [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']),      // 15 EL
            $officer(3, 'US3', [2013 => 'ख', 2014 => 'ख', 2015 => 'ख', 2016 => 'ख', 2017 => 'ख']),      // 10 NE
            $officer(4, 'US4', [2013 => 'ख', 2014 => 'ख', 2015 => 'ख', 2016 => 'ख', 2017 => 'ख']),      // 10 NE
            $officer(5, 'US5', [2013 => 'ख', 2014 => 'ख', 2015 => 'ख', 2016 => 'ख', 2017 => 'ख']),      // 10 NE
        ]);

        // The committee sits on it, the AO presiding, every member agreed (2026-11-18).
        if ($withCommittee) {
            $this->everyMemberAgrees($this->proposal, $this->ao);
        }
    }

    private function calculate(): DpcCalculationRun
    {
        $this->actingAs($this->ao)->post(route('dpc-proposals.calculate', $this->proposal));

        return DpcCalculationRun::query()->latest('id')->firstOrFail();
    }

    // --------------------------------------------------------- calculate button

    /** 2026-11-16: the calculation is the DPC Committee's alone; the department does not see it. */
    public function test_only_the_dpc_committee_sees_and_runs_the_calculation(): void
    {
        $this->seedChart();
        $this->actingAs($this->ao)->post(route('dpc-proposals.calculate', $this->proposal))->assertRedirect();
        $run = DpcCalculationRun::query()->latest('id')->firstOrFail();

        // The AH holds no seat: no card, no history, no result, no new run.
        $this->actingAs($this->ah)->get(route('dpc-proposals.show', $this->proposal))->assertOk()
            ->assertDontSee(route('dpc-proposals.calculations.index', $this->proposal), false);
        $this->get(route('dpc-proposals.calculations.index', $this->proposal))->assertForbidden();
        $this->get(route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]))->assertForbidden();
        $this->post(route('dpc-proposals.calculate', $this->proposal))->assertForbidden();

        // A committee member with no department role reaches it from the sitting.
        $member = User::factory()->create();
        $this->seatAccountOnCommittee($this->proposal, $member);
        $this->actingAs($member)->get(route('sitting.show', $this->proposal))->assertOk()
            ->assertSee('id="committee-calculation"', false);
        $this->get(route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]))->assertOk()
            ->assertSee(route('sitting.show', $this->proposal), false);
    }

    public function test_the_calculate_button_appears_once_a_chart_exists(): void
    {
        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $this->proposal))
            ->assertOk()
            ->assertSee('Eligibility Calculation')
            ->assertSee('nothing to calculate yet')
            ->assertDontSee('CALCULATE</button>', false);

        $this->seedChart();

        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $this->proposal))
            ->assertOk()
            ->assertSee('CALCULATE');
    }

    public function test_calculating_redirects_to_the_result_addressed_by_uuid(): void
    {
        $this->seedChart();

        $response = $this->actingAs($this->ao)
            ->post(route('dpc-proposals.calculate', $this->proposal))
            ->assertSessionHasNoErrors();

        $run = DpcCalculationRun::query()->latest('id')->firstOrFail();

        // The redirect itself names the run by its UUID, never by its sequential id.
        $response->assertRedirect(route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]));

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]))
            ->assertOk();
    }

    public function test_calculating_an_empty_chart_reports_a_business_error(): void
    {
        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.calculate', $this->proposal))
            ->assertRedirect(route('dpc-proposals.show', $this->proposal))
            ->assertSessionHasErrors('calculate');

        $this->assertSame(0, DpcCalculationRun::query()->count());
    }

    public function test_calculation_stays_available_after_the_proposal_is_submitted(): void
    {
        // The Commission calculates after submission, when the proposal itself
        // is no longer editable. Gating this on `update` would make the engine
        // unusable at exactly the point it is needed.
        $this->seedChart(withCommittee: false);
        // The CGPSC Chairman's seat is placed by the service, so the fixture
        // supplies only the ordinary members the size rule asks for.
        // The required combination between them (business, 2026-09-06): a member
        // holds at most one social category, so SC, ST and OBC take one seat each,
        // and the last also carries the three that combine freely. An incomplete
        // committee blocks submission, and this test is about calculation.
        app(DpcCommitteeService::class)->compose($this->proposal, [
            ['name' => 'Member A', 'mobile' => '9000000041', 'capacity' => 'member', 'is_sc' => true],
            ['name' => 'Member B', 'mobile' => '9000000042', 'capacity' => 'member', 'is_st' => true],
            ['name' => 'Member C', 'mobile' => '9000000043', 'capacity' => 'member',
                'is_obc' => true, 'is_female' => true,
                'is_bhar_sadhak_sachiv' => true, 'is_vibhag_sachiv' => true],
        ], $this->ao);
        $this->seatAccountOnCommittee($this->proposal, $this->ao);
        // One vacancy sizes the zone at `single_vacancy_size` (5), and the
        // seniority list must match it exactly before the proposal will submit.
        app(DpcProposalService::class)->saveSeniorityEntries($this->proposal, array_map(
            fn (int $sn): array => [
                'seniority_number' => $sn, 'name' => 'US'.$sn, 'dob' => '1964-03-20',
                'reservation_category_id' => $this->ur->id,
            ],
            range(1, 5),
        ), $this->ao, $this->proposal->seniorityReferenceDate());

        // Each officer's CR, Property Return and PH details (Coder Instructions
        // points 2 and 3), which the gate also requires.
        $this->completeEmployeeDetails($this->proposal->refresh(), $this->ao);

        // Rule 1.1: the department's four confirmations, required by the gate.
        $verification = app(ProposalVerificationService::class);
        foreach (ProposalVerificationItem::cases() as $item) {
            $verification->confirm(
                $this->proposal->refresh(), $item, $this->ao, 'No previous DPC for this post.',
                ['basis' => PreviousDpcBasis::None->value],
                null,
                '2020-01-01',
            );
        }

        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));
        $this->assertSame('submitted', $this->proposal->fresh()->status->value);

        // Submitted, still with the department: not yet the committee's to calculate
        // (2026-11-18). Approved and agreed by every member, it is.
        $this->actingAs($this->ao)->post(route('dpc-proposals.calculate', $this->proposal))
            ->assertSessionHasErrors('calculate');
        $this->everyMemberAgrees($this->proposal->fresh(), $this->ao);

        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.calculate', $this->proposal))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, DpcCalculationRun::query()->count());
    }

    // ------------------------------------------------------------------ history

    public function test_history_lists_every_run_and_never_replaces_one(): void
    {
        $this->seedChart();
        $first = $this->calculate();
        // A second press is refused without a change (2026-11-18); the history still
        // lists every run, however made.
        $second = app(DpcCalculationService::class)->calculate($this->proposal->fresh(), $this->ao);

        $this->actingAs($this->ao)->get(route('dpc-proposals.calculations.index', $this->proposal))
            ->assertOk()
            ->assertSee($first->uuid)
            ->assertSee($second->uuid)
            ->assertSee('Nothing is ever replaced');
    }

    public function test_history_is_empty_before_any_calculation(): void
    {
        $this->actingAs($this->ao)->get(route('dpc-proposals.calculations.index', $this->proposal))
            ->assertOk()
            ->assertSee('No calculation has been run');
    }

    // ------------------------------------------------------- lists and explain

    public function test_the_result_screen_shows_both_lists(): void
    {
        $this->seedChart();
        $run = $this->calculate();

        $this->actingAs($this->ao)->get(route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]))
            ->assertOk()
            ->assertSee('मुख्य सूचि', false)
            ->assertSee('अनुपूरक सूचि', false)
            ->assertSee('US1')
            ->assertSee('US2')
            // Every officer appears, including the one on neither list.
            ->assertSee('US3')
            ->assertSee('All Officers Assessed');
    }

    public function test_the_result_screen_explains_the_run_itself(): void
    {
        $this->seedChart();
        $run = $this->calculate();

        $this->actingAs($this->ao)->get(route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]))
            ->assertOk()
            ->assertSee('How this calculation was produced')
            ->assertSee($run->rule_version);
    }

    public function test_explain_result_is_reachable_per_officer(): void
    {
        $this->seedChart();
        $run = $this->calculate();

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.calculations.explain', [$this->proposal, $run->uuid, 3]))
            ->assertOk()
            ->assertSee('Explain Result')
            ->assertSee('US3')
            ->assertSee('Grounds for refusal')
            ->assertSee('Qualifying score not met');
    }

    public function test_explain_result_shows_the_year_by_year_marks(): void
    {
        $this->seedChart();
        $run = $this->calculate();

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.calculations.explain', [$this->proposal, $run->uuid, 1]))
            ->assertOk()
            ->assertSee('Year-by-year marks')
            ->assertSee('2017');
    }

    public function test_an_unknown_officer_in_a_run_is_not_found(): void
    {
        $this->seedChart();
        $run = $this->calculate();

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.calculations.explain', [$this->proposal, $run->uuid, 99]))
            ->assertNotFound();
    }

    // ------------------------------------------------------------- identifiers

    public function test_runs_are_addressed_by_uuid_and_never_by_sequential_id(): void
    {
        // Architect Decision 5: UUID is the only public identifier.
        $this->seedChart();
        $run = $this->calculate();

        $url = route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]);

        $this->assertStringContainsString($run->uuid, $url);
        $this->assertStringNotContainsString('/calculations/'.$run->id, $url);

        // The sequential id must not resolve.
        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.calculations.show', [$this->proposal, (string) $run->id]))
            ->assertNotFound();
    }

    public function test_the_history_screen_does_not_leak_sequential_ids(): void
    {
        $this->seedChart();
        $run = $this->calculate();

        $this->actingAs($this->ao)->get(route('dpc-proposals.calculations.index', $this->proposal))
            ->assertOk()
            ->assertSee($run->uuid)
            ->assertDontSee('/calculations/'.$run->id);
    }

    // ----------------------------------------------------------- authorization

    public function test_another_departments_officer_cannot_reach_any_calculation_screen(): void
    {
        $this->seedChart();
        $run = $this->calculate();

        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $intruder = User::factory()->assignedTo($other->id, Role::AuthorizedOfficer)->create();

        $this->actingAs($intruder)->get(route('dpc-proposals.calculations.index', $this->proposal))->assertForbidden();
        $this->actingAs($intruder)->get(route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]))->assertForbidden();
        $this->actingAs($intruder)->get(route('dpc-proposals.calculations.explain', [$this->proposal, $run->uuid, 1]))->assertForbidden();
        $this->actingAs($intruder)->post(route('dpc-proposals.calculate', $this->proposal))->assertForbidden();
    }

    public function test_a_run_from_another_proposal_is_not_reachable_through_this_one(): void
    {
        $this->seedChart();
        $run = $this->calculate();

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.store'),
            $this->dpcStorePayload($this->sanctioningPreDpc($this->proposal->promotion_from_post_id, $this->proposal->promotion_to_post_id)),
        );
        $otherProposal = DpcProposal::query()->latest('id')->firstOrFail();
        $this->seatAccountOnCommittee($otherProposal, $this->ao);

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.calculations.show', [$otherProposal, $run->uuid]))
            ->assertNotFound();
    }

    /** 2026-11-16: the calculation is the DPC Committee's alone — not the Administrative Head's. */
    public function test_the_administrative_head_can_neither_view_nor_run_a_calculation(): void
    {
        $this->seedChart();
        $run = $this->calculate();

        $this->actingAs($this->ah)->post(route('dpc-proposals.calculate', $this->proposal))->assertForbidden();
        $this->assertSame(1, DpcCalculationRun::query()->count());
        $this->actingAs($this->ah)
            ->get(route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]))
            ->assertForbidden();
    }

    // ---------------------------------------------- provisional results warning

    public function test_a_provisional_calculation_warns_on_screen(): void
    {
        // A run affected by the unruled reserved relaxation must say so
        // prominently — publishing it as final would publish an outcome under
        // rules nobody has approved.
        $st = ReservationCategory::query()->where('code', 'ST')->firstOrFail();
        $service = app(DpcProposalService::class);

        $this->sanctionVacancies($this->proposal, 10, [[
            'vacancy_type_id' => VacancyType::query()->orderBy('id')->firstOrFail()->id,
            'reservation_category_id' => $st->id,
            'count' => 1,
        ]]);
        $this->seedSeniorityAndChart($this->proposal, [[
            'sn' => 1, 'name' => 'ST1', 'dob' => '1964-03-20',
            'reservation_category_id' => $st->id,
            'cr_grades' => [
                ['year' => 2013, 'grade' => 'ख'], ['year' => 2014, 'grade' => 'ख'],
                ['year' => 2015, 'grade' => 'क'], ['year' => 2016, 'grade' => 'क'],
                ['year' => 2017, 'grade' => 'क'],
            ],
            'loyalty_flags' => [
                ['year' => 2013, 'flag' => true], ['year' => 2014, 'flag' => true],
                ['year' => 2015, 'flag' => true], ['year' => 2016, 'flag' => true],
                ['year' => 2017, 'flag' => true],
            ],
            'property_flags' => [
                ['year' => 2013, 'flag' => true], ['year' => 2014, 'flag' => true],
                ['year' => 2015, 'flag' => true], ['year' => 2016, 'flag' => true],
                ['year' => 2017, 'flag' => true],
            ],
        ]]);

        $this->everyMemberAgrees($this->proposal->fresh(), $this->ao);
        $run = $this->calculate();

        $this->assertTrue($run->has_pending_decisions);

        $this->actingAs($this->ao)->get(route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]))
            ->assertOk()
            ->assertSee('This calculation is provisional')
            ->assertSee('awaiting a business decision');

        $this->actingAs($this->ao)->get(route('dpc-proposals.calculations.index', $this->proposal))
            ->assertOk()
            ->assertSee('awaiting business decision');
    }

    // ------------------------------------- certificate readiness (directive 1)

    public function test_a_run_already_holds_everything_a_calculation_certificate_would_need(): void
    {
        // Architect directive: the persisted data must already be sufficient to
        // generate a certificate later. No PDF is required now — this asserts
        // the data is there so that phase is rendering, not re-deriving.
        $this->seedChart();
        $run = $this->calculate();

        foreach ([
            'uuid', 'rule_version', 'configuration_snapshot', 'proposal_version',
            'application_version', 'git_commit_hash', 'executed_at',
            'zone', 'officers_considered', 'eligible_count', 'main_list_count', 'waiting_list_count',
        ] as $field) {
            $this->assertNotNull($run->{$field}, "A certificate would need {$field}.");
        }

        $result = $run->results()->firstOrFail();
        foreach (['officer_name', 'reservation_category_code', 'score_breakdown', 'verdict', 'super_seat', 'remark'] as $field) {
            $this->assertNotNull($result->{$field}, "A certificate would need result.{$field}.");
        }

        $this->assertNotNull($run->proposal->dpcType);
    }

    // ------------------------------------------------------------- throttling

    public function test_calculate_is_throttled_so_evidence_tables_cannot_be_flooded(): void
    {
        // A calculation writes rows no code path can ever delete. Without a
        // limit, holding the button down grows the evidence tables without
        // bound and buries the run the Commission acted on. Since 2026-11-18 a
        // second press is refused anyway until the data changes; the limit still
        // stands in front of the controller.
        $this->seedChart();

        $max = (int) config('dpc.rate_limiting.calculate.max_attempts');

        $this->actingAs($this->ao)->post(route('dpc-proposals.calculate', $this->proposal))->assertSessionHasNoErrors();
        for ($attempt = 1; $attempt < $max; $attempt++) {
            $this->actingAs($this->ao)->post(route('dpc-proposals.calculate', $this->proposal))
                ->assertSessionHasErrors(['calculate' => __('dpc.calculation_gate.already_calculated')]);
        }

        $this->actingAs($this->ao)
            ->from(route('dpc-proposals.show', $this->proposal))
            ->post(route('dpc-proposals.calculate', $this->proposal))
            ->assertSessionHasErrors(['calculate' => __('Too many calculations in a short time. Please wait a minute before recalculating.')]);

        $this->assertSame(1, DpcCalculationRun::query()->count());
    }

    public function test_the_calculate_limit_is_per_proposal_not_per_user(): void
    {
        // Two proposals are two pieces of work; exhausting one must not stop
        // the other.
        $this->seedChart();

        $max = (int) config('dpc.rate_limiting.calculate.max_attempts');
        for ($attempt = 0; $attempt < $max; $attempt++) {
            $this->actingAs($this->ao)->post(route('dpc-proposals.calculate', $this->proposal));
        }

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.store'),
            $this->dpcStorePayload($this->sanctioningPreDpc($this->proposal->promotion_from_post_id, $this->proposal->promotion_to_post_id)),
        );
        $other = DpcProposal::query()->latest('id')->firstOrFail();
        $this->seatAccountOnCommittee($other, $this->ao);
        $this->everyMemberAgrees($other, $this->ao);

        // The second proposal has no chart, so the business error proves the
        // request reached the controller rather than the throttle.
        $this->actingAs($this->ao)
            ->post(route('dpc-proposals.calculate', $other))
            ->assertRedirect(route('dpc-proposals.show', $other))
            ->assertSessionHasErrors('calculate');

        $this->assertSame(
            'This proposal has no evaluation chart entries, so there is nothing to calculate.',
            session('errors')->first('calculate'),
        );
    }

    // ------------------------------------------- who calculates, and when (2026-11-18)

    /** Calculate waits for every member's Agree, and is the DPC Chairman's alone. */
    public function test_calculate_waits_for_every_member_and_is_the_dpc_chairmans_alone(): void
    {
        $this->seedChart();
        $member = User::factory()->create(['name' => 'Second Member']);
        $this->seatAccountOnCommittee($this->proposal, $member);
        DpcCommitteeDecision::query()->delete();
        DpcCandidateReview::query()->delete();

        // Not accepted by the DPC Chairman: no button, and the POST is refused (2026-11-18).
        $this->actingAs($this->ao)->get(route('sitting.show', $this->proposal))->assertOk()
            ->assertDontSee('id="committee-calculate"', false)->assertSee(__('dpc.calculation_gate.awaiting_accept'));
        $this->post(route('dpc-proposals.calculate', $this->proposal))->assertSessionHasErrors(['calculate' => __('dpc.calculation_gate.awaiting_accept')]);

        // Accepted, but a member's OK is still awaited.
        DpcCommitteeDecision::query()->create(['dpc_proposal_id' => $this->proposal->id, 'user_id' => $this->ao->id, 'member_name' => 'AO', 'decision' => 'agreed', 'decided_at' => now()]);
        $this->post(route('dpc-proposals.calculate', $this->proposal))->assertSessionHasErrors(['calculate' => __('dpc.calculation_gate.awaiting_ok')]);

        // Every OK in: the DPC Chairman has the button; the other member does not.
        $this->everyMemberOksEveryCandidate($this->proposal);
        $this->actingAs($member)->get(route('sitting.show', $this->proposal))->assertDontSee('id="committee-calculate"', false)->assertSee(__('dpc.calculation_gate.only_dpc_chairman'));
        $this->post(route('dpc-proposals.calculate', $this->proposal))->assertSessionHasErrors(['calculate' => __('dpc.calculation_gate.only_dpc_chairman')]);
        $this->assertSame(0, DpcCalculationRun::query()->count());

        $this->actingAs($this->ao)->get(route('sitting.show', $this->proposal))->assertSee('id="committee-calculate"', false);
        $this->post(route('dpc-proposals.calculate', $this->proposal))->assertSessionHasNoErrors();
        $run = DpcCalculationRun::query()->sole();

        // Calculated: every member sees the result; the button is gone.
        $this->actingAs($member)->get(route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]))->assertOk();
        $this->actingAs($this->ao)->get(route('sitting.show', $this->proposal))
            ->assertDontSee('id="committee-calculate"', false)->assertSee(__('dpc.calculation_gate.already_calculated'));
    }

    /** A return to the AO, resubmitted and agreed to afresh, opens Calculate again. */
    public function test_a_resubmitted_dpc_agreed_afresh_may_be_calculated_again(): void
    {
        $this->seedChart();
        $this->actingAs($this->ao)->post(route('dpc-proposals.calculate', $this->proposal))->assertSessionHasNoErrors();
        $gate = app(CalculationGate::class);
        $this->assertFalse($gate->mayCalculate($this->proposal->fresh(), $this->ao));

        // A member returned it; the AO corrected and resubmitted.
        DpcCommitteeDecision::query()->create([
            'dpc_proposal_id' => $this->proposal->id, 'user_id' => $this->ao->id, 'member_name' => 'AO', 'decision' => 'returned',
            'sections' => ['cr'], 'decided_at' => now(), 'resubmitted_at' => now()->addSecond(),
        ]);
        $this->assertFalse($gate->mayCalculate($this->proposal->fresh(), $this->ao), 'Not before every member agrees again.');

        $this->everyMemberAgrees($this->proposal->fresh());
        $this->assertTrue($gate->mayCalculate($this->proposal->fresh(), $this->ao));
    }
}
