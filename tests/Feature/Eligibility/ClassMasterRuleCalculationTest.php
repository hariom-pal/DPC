<?php

declare(strict_types=1);

namespace Tests\Feature\Eligibility;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Enums\RemarkCode;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Eligibility\RuleSet;
use App\Domain\Eligibility\Services\DpcCalculationService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\PromotionCrRule;
use App\Domain\Master\Models\RecordReason;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\SealedCoverReason;
use App\Domain\Master\Models\VacancyType;
use App\Domain\Master\Services\PromotionCrRules;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RaisesDpcProposals;
use Tests\Concerns\SanctionsVacancies;
use Tests\Concerns\SeedsEvaluationCharts;
use Tests\TestCase;

/**
 * The DPC calculation under the Class Master promotion rule and the Eligible CR
 * Grades (business decisions of 2026-10-21): points 9/7/5/3/1 on 1 January of the
 * DPC year; the total of the rule's CR years against its qualifying total; a year
 * below its minimum is not eligible; a mandatory year missing is held (CIR) when its
 * reason and document are recorded and refused (NE) when not; no rule, no
 * calculation.
 */
class ClassMasterRuleCalculationTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;
    use SanctionsVacancies;
    use SeedsEvaluationCharts;

    private const YEARS = [2021, 2022, 2023, 2024, 2025];

    private User $ao;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $department = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($department->id, Role::AuthorizedOfficer)->create();

        $from = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Block Education Officer', 'class_level' => 'II', 'status' => 'active']);
        $to = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Additional Director', 'class_level' => 'I', 'status' => 'active']);

        // DPC year 2026.
        $this->proposal = $this->raiseDpc($this->ao, $from, $to);
    }

    /**
     * Class II → Class I from 2026: five CR years, a qualifying total of 31, 2025
     * mandatory with a minimum of 5, a tribal bonus of 2 and Super Seat at 9 points.
     */
    private function rule(int $total = 31): PromotionCrRule
    {
        $rule = PromotionCrRule::query()->create([
            'to_class' => 'I', 'from_class' => 'II', 'effective_from_year' => 2026,
            'cr_years' => 5, 'loyalty_years' => 0, 'property_years' => 0,
            'qualifying_total' => $total, 'qualifying_average' => round($total / 5, 2),
            'super_seat_grade' => 9, 'tribal_bonus' => 2,
        ]);

        foreach (range(1, 5) as $before) {
            $rule->years()->create([
                'years_before' => $before,
                'cr_min_marks' => $before === 1 ? 5 : null,
                'cr_mandatory' => $before === 1,
                'loyalty_mandatory' => false,
                'property_mandatory' => false,
            ]);
        }

        // The rule fixed on the proposal when it was raised.
        $this->proposal->forceFill(['promotion_cr_rule_id' => $rule->id])->save();

        return $rule;
    }

    /**
     * @param  array<int, string>  $grades  year => grade
     */
    private function officer(int $sn, array $grades, array $tribalYears = []): array
    {
        $years = array_keys($grades);

        return [
            'sn' => $sn,
            'name' => "Officer {$sn}",
            'employee_number' => "EMP-{$sn}",
            'dob' => '1970-03-20',
            'reservation_category_id' => ReservationCategory::query()->where('code', 'UR')->firstOrFail()->id,
            'cr_grades' => array_map(static fn (int $year, string $grade): array => [
                'year' => $year, 'grade' => $grade, 'tribal_area' => in_array($year, $tribalYears, true),
            ], $years, array_values($grades)),
            'loyalty_flags' => array_map(static fn (int $year): array => ['year' => $year, 'flag' => true], $years),
            // Five continuous Property Returns (item 13), whichever CR years are missing.
            'property_flags' => array_map(static fn (int $year): array => ['year' => $year, 'flag' => true], range(2020, 2025)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $officers
     * @return array<int, DpcEvaluationRow>
     */
    private function chart(array $officers): array
    {
        $vacancyType = VacancyType::query()->orderBy('id')->firstOrFail();
        $this->sanctionVacancies($this->proposal, 10, [[
            'vacancy_type_id' => $vacancyType->id,
            'reservation_category_id' => ReservationCategory::query()->where('code', 'UR')->firstOrFail()->id,
            'count' => 2,
        ]]);
        $this->seedSeniorityAndChart($this->proposal, $officers);

        return $this->proposal->fresh()->evaluationRows()->get()->keyBy('sn')->all();
    }

    private function five(string $grade): array
    {
        return array_fill_keys(self::YEARS, $grade);
    }

    private function calculate(): DpcCalculationRun
    {
        return app(DpcCalculationService::class)->calculate($this->proposal->fresh(), $this->ao);
    }

    private function resultFor(DpcCalculationRun $run, int $sn): DpcCalculationResult
    {
        return $run->results()->where('sn', $sn)->firstOrFail();
    }

    public function test_a_dpc_is_raised_only_under_a_rule_and_keeps_it(): void
    {
        // The rule in force when this DPC was raised is fixed on it and shown.
        $fixed = $this->proposal->promotion_cr_rule_id;
        $this->assertNotNull($fixed);
        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $this->proposal))
            ->assertOk()
            ->assertSee('id="fixed-promotion-rule" data-rule-id="'.$fixed.'"', false)
            // Rule 1.5: effective from 1 January of the DPC year, vacancies to 31 December.
            ->assertSee(__('dpc.sections.post_selection.effective_date_value', ['date' => '01-01-2026', 'end' => '31-12-2026']));

        // A rule recorded afterwards does not reach it.
        $later = PromotionCrRule::query()->create([
            'to_class' => 'I', 'from_class' => 'II', 'effective_from_year' => 2026, 'cr_years' => 5,
            'loyalty_years' => 0, 'property_years' => 0, 'qualifying_total' => 99, 'qualifying_average' => 19.8,
        ]);
        $this->assertSame($fixed, app(PromotionCrRules::class)->ruleForProposal($this->proposal->fresh())?->id);
        $this->assertNotSame($later->id, $fixed);

        // Without a rule for the class pair no DPC is raised.
        $department = $this->proposal->department;
        $from = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Teacher', 'class_level' => 'III', 'status' => 'active']);
        $to = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Head Master', 'class_level' => 'II', 'status' => 'active']);
        $preDpc = $this->sanctioningPreDpc($from, $to);
        PromotionCrRule::query()->where('from_class', 'III')->where('to_class', 'II')->delete();

        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), $this->dpcStorePayload($preDpc, ['promotion_from_post_id' => $from->id]))
            ->assertSessionHasErrors(['pre_dpc_proposal_id' => __('dpc.calculation_rules.no_rule_to_raise', ['from' => 'III', 'to' => 'II', 'year' => 2026])]);
        $this->assertSame(1, DpcProposal::query()->count());
    }

    public function test_a_dpc_raised_before_rules_were_fixed_is_not_calculated_without_one(): void
    {
        $this->chart([$this->officer(1, $this->five('क'))]);
        $this->proposal->forceFill(['promotion_cr_rule_id' => null])->save();
        PromotionCrRule::query()->where('from_class', 'II')->where('to_class', 'I')->delete();

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage(__('dpc.calculation_rules.no_promotion_rule', ['from' => 'II', 'to' => 'I', 'year' => 2026]));

        $this->calculate();
    }

    public function test_the_total_of_the_points_is_held_against_the_qualifying_total(): void
    {
        $this->rule(total: 35);
        $this->chart([
            $this->officer(1, $this->five('क')),                                   // 5 × 7 = 35
            $this->officer(2, [...$this->five('क'), 2022 => 'ख']),                // 33
        ]);

        $run = $this->calculate();

        $one = $this->resultFor($run, 1);
        $this->assertSame(35, $one->score_breakdown['total']);
        $this->assertEquals([2021 => 7, 2022 => 7, 2023 => 7, 2024 => 7, 2025 => 7], array_map('intval', $one->score_breakdown['marks_by_year']));
        $this->assertTrue($one->is_eligible);

        $two = $this->resultFor($run, 2);
        $this->assertSame(33, $two->score_breakdown['total']);
        $this->assertFalse($two->is_eligible);
        $this->assertSame(RemarkCode::NotEligible->value, $two->remark);
        $this->assertSame(35, $two->verdict['required_score']);
    }

    public function test_a_year_below_its_minimum_is_not_eligible_whatever_the_total(): void
    {
        $this->rule(total: 31);
        // क+ for four years and ग (3) in 2025, whose minimum is 5: 39 points, still refused.
        $this->chart([$this->officer(1, [...$this->five('क+'), 2025 => 'ग'])]);

        $result = $this->resultFor($this->calculate(), 1);

        $this->assertSame(39, $result->score_breakdown['total']);
        $this->assertFalse($result->is_eligible);
        $this->assertStringContainsString('CR 2025 scored 3, below the minimum of 5', implode(' ', $result->verdict['failure_reasons']));
    }

    public function test_several_crs_in_a_year_score_their_average_on_the_points(): void
    {
        $this->rule(total: 31);
        $row = $this->chart([$this->officer(1, $this->five('क'))])[1];

        // 2025: one क+ and one क — (9 + 7) / 2 = 8, a value no single grade has.
        $row->crGrades()->where('year', 2025)->update(['reports' => json_encode([
            ['reporter_grade' => 'घ', 'reviewer_grade' => 'ख', 'accepting_grade' => 'क+'],
            ['reporter_grade' => 'क', 'reviewer_grade' => null, 'accepting_grade' => null],
        ])]);

        $result = $this->resultFor($this->calculate(), 1);

        $this->assertSame(8, (int) $result->score_breakdown['marks_by_year'][2025]);
        $this->assertSame(36, $result->score_breakdown['total']);
    }

    public function test_a_mandatory_year_missing_is_refused_or_held_on_its_reason_and_document(): void
    {
        $this->rule(total: 31);
        // Five CRs, but not 2025 — the year the rule makes mandatory.
        $rows = $this->chart([
            $this->officer(1, [2020 => 'क', 2021 => 'क', 2022 => 'क', 2023 => 'क', 2024 => 'क']),
            $this->officer(2, [2020 => 'क', 2021 => 'क', 2022 => 'क', 2023 => 'क', 2024 => 'क']),
        ]);

        // Officer 2: reason and document recorded (rule 3.2).
        $rows[2]->recordGaps()->create([
            'kind' => DpcEvaluationRecordGap::KIND_CR, 'year' => 2025,
            'record_reason_id' => RecordReason::query()->where('code', 'record_not_available')->firstOrFail()->id,
            'disk' => 'local', 'path' => 'gaps/cr-2025.pdf', 'original_name' => 'cr-2025.pdf',
            'size_bytes' => 1024, 'sha256' => str_repeat('a', 64),
        ]);

        $run = $this->calculate();

        $refused = $this->resultFor($run, 1);
        $this->assertSame(RemarkCode::NotEligible->value, $refused->remark);
        $this->assertStringContainsString('The CR for 2025 is mandatory under the promotion rule', json_encode($refused->verdict, JSON_UNESCAPED_UNICODE));

        $this->assertSame(RemarkCode::Circulation->value, $this->resultFor($run, 2)->remark);
    }

    public function test_super_seat_needs_every_year_at_the_rules_points(): void
    {
        $this->rule(total: 31);
        $run = (function () {
            $this->chart([
                $this->officer(1, $this->five('क')),
                $this->officer(2, $this->five('क+')),
            ]);

            return $this->calculate();
        })();

        $this->assertFalse($this->resultFor($run, 1)->is_super_seat);
        $this->assertTrue($this->resultFor($run, 2)->is_super_seat);
        $this->assertSame(45, $this->resultFor($run, 2)->score_breakdown['total']);
    }

    public function test_the_tribal_bonus_is_given_per_tribal_year_but_not_at_the_highest_or_lowest_grade(): void
    {
        $this->rule(total: 31);
        // Tribal in 2021 (क), 2022 (क+, the highest), 2023 (ख) and 2024 (घ, the lowest).
        $this->chart([$this->officer(1, [2021 => 'क', 2022 => 'क+', 2023 => 'ख', 2024 => 'घ', 2025 => 'क'], [2021, 2022, 2023, 2024])]);

        $score = $this->resultFor($this->calculate(), 1)->score_breakdown;

        // 7 + 9 + 5 + 1 + 7 = 29, and a bonus of 2 for 2021 and 2023 only.
        $this->assertSame(29, $score['base_total']);
        $this->assertEqualsCanonicalizing([2021, 2023], $score['tribal_bonus_years']);
        $this->assertSame(4, $score['tribal_area_bonus']);
        $this->assertSame(33, $score['total']);
    }

    public function test_without_the_setting_every_tribal_year_earns_the_bonus(): void
    {
        $this->rule(total: 31)->forceFill(['tribal_bonus_skips_extremes' => false])->save();
        $this->chart([$this->officer(1, [2021 => 'क', 2022 => 'क+', 2023 => 'ख', 2024 => 'घ', 2025 => 'क'], [2021, 2022, 2023, 2024])]);

        $score = $this->resultFor($this->calculate(), 1)->score_breakdown;

        $this->assertEqualsCanonicalizing([2021, 2022, 2023, 2024], $score['tribal_bonus_years']);
        $this->assertSame(37, $score['total']);
    }

    private function underSealedCover(DpcEvaluationRow $row, string $reasonCode): void
    {
        $row->forceFill([
            'vigilance_pending' => true,
            'sealed_cover_reason_id' => SealedCoverReason::query()->where('code', $reasonCode)->firstOrFail()->id,
            'vigilance_date' => '2025-06-01',
        ])->save();
    }

    public function test_a_sealed_cover_officer_keeps_the_post_held_and_is_shown_as_sealed_cover(): void
    {
        $this->rule(total: 31);
        // Two vacancies; the senior officer has a charge-sheet pending.
        $rows = $this->chart([
            $this->officer(1, $this->five('क')),
            $this->officer(2, $this->five('क')),
            $this->officer(3, $this->five('क')),
        ]);
        $this->underSealedCover($rows[1], 'de_chargesheet');

        $run = $this->calculate();
        $sealed = $this->resultFor($run, 1);

        // The post is held for the officer, not passed to the next one.
        $this->assertSame(['main', 1, 'SCOVER'], [$sealed->list_type->value, $sealed->list_position, $sealed->remark]);
        $this->assertSame(RemarkCode::SealedCover, $sealed->status());
        $this->assertStringContainsString('Departmental enquiry instituted and charge-sheet issued', (string) $sealed->verdict['sealed_cover']);
        $this->assertSame('main', $this->resultFor($run, 2)->list_type->value);
        $this->assertSame('waiting', $this->resultFor($run, 3)->list_type->value);

        $this->actingAs($this->ao)->get(route('dpc-proposals.calculations.show', [$this->proposal, $run]))
            ->assertOk()
            ->assertSee('Sealed Cover');
    }

    public function test_a_show_cause_notice_alone_does_not_seal_the_cover(): void
    {
        $this->rule(total: 31);
        $rows = $this->chart([$this->officer(1, $this->five('क'))]);
        $this->underSealedCover($rows[1], 'show_cause_only');

        $result = $this->resultFor($this->calculate(), 1);

        $this->assertSame(RemarkCode::Eligible, $result->status());
        $this->assertNotSame('SCOVER', $result->remark);
        $this->assertArrayNotHasKey('sealed_cover', $result->verdict);
    }

    public function test_unsanctioned_deputation_and_a_refusal_last_year_are_not_eligible(): void
    {
        $this->rule(total: 31);
        $rows = $this->chart([
            $this->officer(1, $this->five('क')),
            $this->officer(2, $this->five('क')),
            $this->officer(3, $this->five('क')),
        ]);
        $rows[1]->forceFill(['unsanctioned_deputation' => true])->save();
        $rows[2]->forceFill(['refused_promotion' => true, 'refused_promotion_year' => 2025])->save();

        $run = $this->calculate();

        $this->assertSame(RemarkCode::NotEligible->value, $this->resultFor($run, 1)->remark);
        $this->assertStringContainsString('rule 2.3', json_encode($this->resultFor($run, 1)->verdict));
        $this->assertSame(RemarkCode::NotEligible->value, $this->resultFor($run, 2)->remark);
        $this->assertStringContainsString('rule 4.4', json_encode($this->resultFor($run, 2)->verdict));
        $this->assertSame('main', $this->resultFor($run, 3)->list_type->value);
    }

    public function test_a_verified_ph_claim_takes_a_ph_seat_first(): void
    {
        // Rule 2.1: 50 % of 2 vacancies is one PH seat (the policy's percentage, raised for the test).
        DB::table('reservation_policies')->update(['percentage' => 50]);
        config(['dpc.employee_details.ph_quota_percent' => 50]);

        $this->rule(total: 31);
        $rows = $this->chart([
            $this->officer(1, $this->five('क')),
            $this->officer(2, $this->five('क')),
            $this->officer(3, $this->five('क')),
        ]);
        // The junior officer's PH claim, verified by the Administrative Head.
        $rows[3]->forceFill([
            'is_ph' => true, 'ph_previously_availed' => false, 'ph_claims_reservation' => true,
            'ph_verification_status' => 'verified', 'ph_final_decision' => 'benefit',
        ])->save();

        $run = $this->calculate();

        $this->assertSame(['main', 'PH'], [$this->resultFor($run, 3)->list_type->value, $this->resultFor($run, 3)->remark]);
        $this->assertSame('main', $this->resultFor($run, 1)->list_type->value);
        $this->assertSame('waiting', $this->resultFor($run, 2)->list_type->value, 'The PH seat was set aside first.');
    }

    public function test_an_unverified_ph_claim_takes_no_ph_seat(): void
    {
        DB::table('reservation_policies')->update(['percentage' => 50]);
        config(['dpc.employee_details.ph_quota_percent' => 50]);

        $this->rule(total: 31);
        $rows = $this->chart([
            $this->officer(1, $this->five('क')),
            $this->officer(2, $this->five('क')),
            $this->officer(3, $this->five('क')),
        ]);
        $rows[3]->forceFill([
            'is_ph' => true, 'ph_previously_availed' => false, 'ph_claims_reservation' => true,
            'ph_verification_status' => 'not_verified', 'ph_final_decision' => 'no_benefit',
        ])->save();

        $run = $this->calculate();

        $this->assertSame('main', $this->resultFor($run, 2)->list_type->value);
        $this->assertSame('waiting', $this->resultFor($run, 3)->list_type->value);
    }

    public function test_the_run_keeps_the_rule_it_was_calculated_under(): void
    {
        $rule = $this->rule(total: 31);
        $this->chart([$this->officer(1, $this->five('क'))]);

        $run = $this->calculate();
        $snapshot = $run->configuration_snapshot;

        $this->assertSame($rule->id, $snapshot['class_rule']['rule_id']);
        $this->assertSame(['क+' => 9, 'क' => 7, 'ख' => 5, 'ग' => 3, 'घ' => 1], $snapshot['cr_grade_marks']);

        // The rule changing afterwards changes nothing already calculated.
        $rule->forceFill(['qualifying_total' => 99])->save();
        $rebuilt = RuleSet::fromSnapshot($run->fresh()->configuration_snapshot);
        $this->assertSame(31, $rebuilt->classRule['qualifying_total']);
        $this->assertSame(5, $rebuilt->minimumFor(2025));
        $this->assertSame([2025], $rebuilt->mandatoryCrYears());
        $this->assertSame(['year', true, 9, 1], [
            $rebuilt->classRule['tribal_bonus_basis'], $rebuilt->classRule['tribal_bonus_skips_extremes'],
            $rebuilt->classRule['highest_points'], $rebuilt->classRule['lowest_points'],
        ]);
    }
}
