<?php

declare(strict_types=1);

namespace Tests\Feature\Eligibility;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Enums\ListType;
use App\Domain\Eligibility\Enums\RemarkCode;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Eligibility\Services\DpcCalculationService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\PayScale;
use App\Domain\Master\Models\RecordReason;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RaisesDpcProposals;
use Tests\Concerns\SanctionsVacancies;
use Tests\Concerns\SeedsEvaluationCharts;
use Tests\Concerns\UsesPptPromotionRules;
use Tests\TestCase;
use Tests\Unit\Eligibility\CandidateGateServiceTest;

/**
 * The merit gates, end to end — change register of 2026-08-27, items 11 to 20 and 24.
 *
 * {@see CandidateGateServiceTest} pins the *rules* against a
 * pure service. This class pins the *wiring*: that a gap saved on a chart row reaches
 * the gate, that the status is persisted where the promotion list reads it, and that a
 * gated candidate is kept off the Main List. Those are three places the rules could be
 * correct and the outcome still wrong.
 *
 * It deliberately does not re-test the marking formula, the qualifying score or Super
 * Seat. Items 21 to 23 say those are the PPT's and are not to be modified, and they
 * have their own golden tests — a second copy of those assertions here would be a
 * second place to update if the deck ever changes.
 */
class MeritGateTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;
    use SanctionsVacancies;
    use SeedsEvaluationCharts;
    use UsesPptPromotionRules;

    private const YEARS = [2013, 2014, 2015, 2016, 2017];

    private Department $department;

    private User $ao;

    private DpcProposal $proposal;

    private ReservationCategory $ur;

    private DepartmentPost $postFrom;

    private DepartmentPost $postTo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create([
            'code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active',
        ]);
        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();

        $this->postFrom = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Under Secretary',
            'class_level' => 'I', 'status' => 'active',
        ]);
        $this->postTo = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Deputy Secretary',
            'class_level' => 'I', 'status' => 'active',
        ]);

        $this->ur = ReservationCategory::query()->where('code', 'UR')->firstOrFail();

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.store'),
            $this->dpcStorePayload($this->sanctioningPreDpc($this->postFrom->id, $this->postTo->id)),
        );
        $this->proposal = DpcProposal::query()->latest('id')->firstOrFail();
    }

    // =====================================================================
    // Item 13 — Property Return
    // =====================================================================

    /**
     * A chart missing one Property Return year produces a persisted `RJ`, with the
     * ground stored beside it.
     *
     * The reason is asserted as *stored*, not as rendered: a rejection an officer
     * cannot read the ground for is indefensible if the promotion is challenged, and
     * the stored verdict is what the Explain Result screen and the printed list both
     * read from.
     */
    public function test_a_missing_property_return_is_persisted_as_a_rejection(): void
    {
        $this->chart([['sn' => 1, 'name' => 'US1', 'propertyMissing' => [2015]]]);

        $result = $this->calculate()->results()->where('sn', 1)->firstOrFail();

        $this->assertFalse($result->is_eligible);
        $this->assertSame(RemarkCode::Rejected->value, $result->remark);
        $this->assertSame(ListType::None, $result->list_type);
        $this->assertStringContainsString(
            'Property Return not available for mandatory 5 continuous years',
            implode(' ', $result->verdict['failure_reasons']),
        );
    }

    /**
     * A rejected candidate does not consume a vacancy.
     *
     * The point of the gate: with one seat and two officers, the seat goes to the one
     * who is in contention. A rejection that still took the seat would leave the post
     * filled by someone who is out.
     */
    public function test_a_rejected_candidate_does_not_take_a_seat(): void
    {
        $this->chart([
            ['sn' => 1, 'name' => 'US1', 'propertyMissing' => [2015]],
            ['sn' => 2, 'name' => 'US2'],
        ], sanctioned: 1, vacancies: 1);

        $run = $this->calculate();

        $this->assertSame(
            ListType::None,
            $run->results()->where('sn', 1)->firstOrFail()->list_type,
        );
        $this->assertSame(
            ListType::Main,
            $run->results()->where('sn', 2)->firstOrFail()->list_type,
        );
    }

    // =====================================================================
    // Items 14, 15 and 19 — CIR
    // =====================================================================

    /**
     * A missing CR with a reason **and** a supporting document reaches the gate as
     * justified, and the candidate is held at `CIR` rather than refused.
     *
     * This is the wiring the unit test cannot check: the gap is written to
     * `dpc_evaluation_record_gaps` with a `record_reasons` row and a stored file, and
     * the point is that `OfficerRecord::fromEvaluationRow()` reads all three back out.
     */
    public function test_a_justified_missing_cr_is_persisted_as_cir(): void
    {
        $row = $this->chart([[
            'sn' => 1, 'name' => 'US1',
            'crYears' => [2014, 2015, 2016, 2017],
        ]])[1];

        $this->recordGap($row, DpcEvaluationRecordGap::KIND_CR, 2013);

        $result = $this->calculate()->results()->where('sn', 1)->firstOrFail();

        $this->assertSame(RemarkCode::Circulation->value, $result->remark);
        $this->assertNotSame([], $result->verdict['gate_citations']);
    }

    /**
     * Item 19 requires the reason **and** the PDF, and makes both mandatory — so a
     * reason saved with no file has not met the condition that opens the CIR path.
     *
     * Pinned because the failure mode is silent and generous: treating a
     * document-less reason as justified would let the upload be skipped by saving the
     * form and never returning to it, which is the one way this rule can be evaded.
     */
    public function test_a_reason_without_its_document_does_not_earn_cir(): void
    {
        $row = $this->chart([[
            'sn' => 1, 'name' => 'US1',
            'crYears' => [2014, 2015, 2016, 2017],
        ]])[1];

        $this->recordGap($row, DpcEvaluationRecordGap::KIND_CR, 2013, withDocument: false);

        $result = $this->calculate()->results()->where('sn', 1)->firstOrFail();

        $this->assertNotSame(RemarkCode::Circulation->value, $result->remark);
        $this->assertStringContainsString(
            'no supporting document',
            implode(' ', $result->verdict['failure_reasons']),
        );
    }

    /**
     * Item 15 — a Loyalty Certificate belongs to a CR year, so a justified gap is
     * recorded against that year and produces the same hold.
     */
    public function test_a_justified_missing_loyalty_certificate_is_persisted_as_cir(): void
    {
        $row = $this->chart([[
            'sn' => 1, 'name' => 'US1',
            'loyaltyMissing' => [2016],
        ]])[1];

        $this->recordGap($row, DpcEvaluationRecordGap::KIND_LOYALTY, 2016);

        $result = $this->calculate()->results()->where('sn', 1)->firstOrFail();

        $this->assertSame(RemarkCode::Circulation->value, $result->remark);
    }

    /**
     * Item 19: *"Candidate shall continue in the DPC."*
     *
     * So a CIR candidate is still scored — the marks are computed and stored, not
     * skipped. The promotion is held, which is why the seat is not taken; but the
     * record is complete enough for the Commission to act on when the missing document
     * arrives, without re-running anything.
     */
    public function test_a_cir_candidate_is_still_scored_but_takes_no_seat(): void
    {
        $row = $this->chart([[
            'sn' => 1, 'name' => 'US1',
            'crYears' => [2014, 2015, 2016, 2017],
        ]], sanctioned: 1, vacancies: 1)[1];

        $this->recordGap($row, DpcEvaluationRecordGap::KIND_CR, 2013);

        $result = $this->calculate()->results()->where('sn', 1)->firstOrFail();

        $this->assertSame(RemarkCode::Circulation->value, $result->remark);
        $this->assertGreaterThan(0, $result->score_breakdown['total']);
        $this->assertSame(ListType::None, $result->list_type);
    }

    // =====================================================================
    // Item 17 — newly appointed
    // =====================================================================

    /**
     * A two-year officer has two CRs, which is all there is to have.
     *
     * > *The candidate shall not be rejected only because five years of service has
     * > not yet been completed.*
     *
     * With the absent years justified they land on CIR. Their Property Returns are
     * still owed for the full window — item 17 relaxes the records that *cannot*
     * exist, not the ones that can.
     */
    public function test_a_newly_appointed_officer_is_held_rather_than_rejected(): void
    {
        $row = $this->chart([[
            'sn' => 1, 'name' => 'US1',
            'crYears' => [2016, 2017],
        ]])[1];

        foreach ([2013, 2014, 2015] as $year) {
            $this->recordGap($row, DpcEvaluationRecordGap::KIND_CR, $year, reasonCode: 'newly_appointed');
        }

        $result = $this->calculate()->results()->where('sn', 1)->firstOrFail();

        $this->assertSame(RemarkCode::Circulation->value, $result->remark);
        $this->assertNotSame(RemarkCode::Rejected->value, $result->remark);
    }

    // =====================================================================
    // Item 11 — the hierarchy
    // =====================================================================

    /**
     * A proposal whose promotion runs downhill rejects **every** candidate, and each
     * one carries the ground.
     *
     * The check is made once for the proposal — the post pair is the same for
     * everybody — but the *status* is per candidate, because a status is something a
     * candidate has and the printed list carries it per row.
     */
    public function test_a_downhill_promotion_rejects_every_candidate(): void
    {
        $high = PayScale::query()->create(['label' => 'L-14', 'code' => 'L14', 'level' => 14, 'grade_pay' => 6600]);
        $low = PayScale::query()->create(['label' => 'L-10', 'code' => 'L10', 'level' => 10, 'grade_pay' => 4200]);

        $this->postFrom->update(['pay_scale_id' => $high->id]);
        $this->postTo->update(['pay_scale_id' => $low->id]);

        $this->chart([
            ['sn' => 1, 'name' => 'US1'],
            ['sn' => 2, 'name' => 'US2'],
        ]);

        $run = $this->calculate();

        foreach ([1, 2] as $sn) {
            $result = $run->results()->where('sn', $sn)->firstOrFail();

            $this->assertSame(RemarkCode::Rejected->value, $result->remark, "officer {$sn}");
            $this->assertStringContainsString(
                'lower scale',
                implode(' ', $result->verdict['failure_reasons']),
            );
        }
    }

    // =====================================================================
    // Item 24 — the four statuses
    // =====================================================================

    /**
     * All four statuses reachable in one run, which is the register's item 24 in one
     * assertion.
     *
     * `NE` here is a *merit* failure rather than a gate one — an officer whose marks
     * fall short of the minimum — so the run exercises both paths to a status: the
     * gates and the untouched formula.
     */
    public function test_one_run_can_reach_all_four_statuses(): void
    {
        $rows = $this->chart([
            // Clean and top-graded — eligible, and takes the one seat.
            ['sn' => 1, 'name' => 'EL1', 'grade' => 'क+'],
            // Ordinary grades throughout — scored, and short of the minimum.
            ['sn' => 2, 'name' => 'NE1', 'grade' => 'ख'],
            // A justified gap — held.
            ['sn' => 3, 'name' => 'CIR1', 'grade' => 'क+', 'crYears' => [2014, 2015, 2016, 2017]],
            // A missing Property Return — out.
            ['sn' => 4, 'name' => 'RJ1', 'grade' => 'क+', 'propertyMissing' => [2015]],
        ], sanctioned: 1, vacancies: 1);

        $this->recordGap($rows[3], DpcEvaluationRecordGap::KIND_CR, 2013);

        $run = $this->calculate();

        $remarks = $run->results()->orderBy('sn')->pluck('remark', 'officer_name')->all();

        // The eligible officer took the seat, so their remark is the category they
        // took it under — the list's own vocabulary, unchanged by this register.
        $this->assertSame('UR', $remarks['EL1']);
        $this->assertSame(RemarkCode::NotEligible->value, $remarks['NE1']);
        $this->assertSame(RemarkCode::Circulation->value, $remarks['CIR1']);
        $this->assertSame(RemarkCode::Rejected->value, $remarks['RJ1']);
    }

    // =====================================================================
    // Item 24 on the screen
    // =====================================================================

    /**
     * The result screen prints the status the register names, not a boolean.
     *
     * This is the display half of item 24, and it is the half that matters to the
     * officer: a held promotion shown as "Not Eligible" is a materially different and
     * more damaging statement than "CIR", and it is the one a government promotion
     * list would carry into an appeal.
     *
     * The awaited record is shown beside it, because "CIR" alone does not say what
     * is being waited for (item 19).
     */
    public function test_the_result_screen_prints_cir_rather_than_not_eligible(): void
    {
        $row = $this->chart([[
            'sn' => 1, 'name' => 'CIR1', 'grade' => 'क+',
            'crYears' => [2014, 2015, 2016, 2017],
        ]])[1];

        $this->recordGap($row, DpcEvaluationRecordGap::KIND_CR, 2013);

        $run = $this->calculate();

        $response = $this->actingAs($this->ao)
            ->get(route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]));

        $response->assertOk();
        $response->assertSee('CIR');
        $response->assertDontSee('Not Eligible');
        // The citation names the record that is holding the promotion.
        $response->assertSee('2013');
    }

    /**
     * And a rejection prints as a rejection.
     *
     * Pinned beside the CIR case because the two share one code path — a display that
     * got the status from `is_eligible` would show both as "Not Eligible" and pass
     * neither test.
     */
    public function test_the_result_screen_prints_rejected(): void
    {
        $this->chart([['sn' => 1, 'name' => 'RJ1', 'propertyMissing' => [2015]]]);

        $run = $this->calculate();

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.calculations.show', [$this->proposal, $run->uuid]))
            ->assertOk()
            ->assertSee('Rejected');
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    /**
     * Build a Seniority List and its projected chart.
     *
     * @param  list<array<string, mixed>>  $officers
     * @return array<int, DpcEvaluationRow> keyed by sn
     */
    private function chart(array $officers, int $sanctioned = 10, int $vacancies = 5): array
    {
        $this->sanctionVacancies($this->proposal, $sanctioned, [[
            'vacancy_type_id' => VacancyType::query()->orderBy('id')->firstOrFail()->id,
            'reservation_category_id' => $this->ur->id,
            'count' => $vacancies,
        ]]);

        $this->seedSeniorityAndChart($this->proposal, array_map(
            function (array $officer): array {
                $crYears = $officer['crYears'] ?? self::YEARS;
                $grade = $officer['grade'] ?? 'क';
                $propertyMissing = $officer['propertyMissing'] ?? [];
                $loyaltyMissing = $officer['loyaltyMissing'] ?? [];

                return [
                    'sn' => $officer['sn'],
                    'name' => $officer['name'],
                    'dob' => '1964-03-20',
                    'qualification' => $officer['qualification'] ?? 'B.A.',
                    'reservation_category_id' => $this->ur->id,
                    'cr_grades' => array_map(
                        static fn (int $year): array => ['year' => $year, 'grade' => $grade],
                        $crYears,
                    ),
                    'loyalty_flags' => array_values(array_map(
                        static fn (int $year): array => [
                            'year' => $year,
                            'flag' => in_array($year, $loyaltyMissing, true) ? null : true,
                        ],
                        $crYears,
                    )),
                    /*
                     | Property covers the full window, not just the CR years.
                     |
                     | Item 13's window is continuous and independent of the CR chart,
                     | so a short-CR officer still owes five returns. Deriving these
                     | from `$crYears` would make every CIR fixture above reject on the
                     | property ground instead — the right answer for the wrong reason.
                     */
                    'property_flags' => array_map(
                        static fn (int $year): array => [
                            'year' => $year,
                            'flag' => in_array($year, $propertyMissing, true) ? null : true,
                        ],
                        self::YEARS,
                    ),
                ];
            },
            $officers,
        ));

        return $this->proposal->fresh()->evaluationRows()->get()->keyBy('sn')->all();
    }

    /**
     * Record a justified missing record: a reason from the master, and a document.
     *
     * `withDocument: false` writes the reason and no file, which is the case item 19
     * refuses to accept as a justification.
     */
    private function recordGap(
        DpcEvaluationRow $row,
        string $kind,
        int $year,
        string $reasonCode = 'record_not_available',
        bool $withDocument = true,
    ): DpcEvaluationRecordGap {
        $reason = RecordReason::query()->where('code', $reasonCode)->firstOrFail();

        return $row->recordGaps()->create([
            'kind' => $kind,
            'year' => $year,
            'record_reason_id' => $reason->id,
            'remarks' => null,
            'disk' => $withDocument ? 'local' : null,
            'path' => $withDocument ? "gaps/{$kind}-{$year}.pdf" : null,
            'original_name' => $withDocument ? "{$kind}-{$year}.pdf" : null,
            'size_bytes' => $withDocument ? 1024 : null,
            'sha256' => $withDocument ? str_repeat('a', 64) : null,
        ]);
    }

    private function calculate(): DpcCalculationRun
    {
        app(DpcCalculationService::class)->calculate($this->proposal->fresh(), $this->ao);

        return DpcCalculationRun::query()->latest('id')->firstOrFail();
    }
}
