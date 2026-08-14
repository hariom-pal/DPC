<?php

declare(strict_types=1);

namespace Tests\Feature\Proceeding;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Cgpsc\Services\SchedulingService;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Proceeding\Enums\CorrectableField;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingOverride;
use App\Domain\Proceeding\Services\ProceedingCorrectionService;
use App\Domain\Proceeding\Services\ProceedingService;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * Commission corrections during a sitting (PPT S33 EDIT / TRIBAL / SAVE) —
 * M7 Phase 2.
 */
class ProceedingCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private User $chairman;

    private User $memberOne;

    private User $memberTwo;

    private DpcProposal $proposal;

    private DpcProceeding $proceeding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $department = Department::query()->create([
            'code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active',
        ]);

        $this->chairman = User::factory()->create(['department_id' => null, 'name' => 'Hon. Chairman']);
        $this->chairman->assignRole(Role::Chairman->value);

        $this->memberOne = User::factory()->create(['department_id' => null, 'name' => 'Member One']);
        $this->memberOne->assignRole(Role::Member->value);

        $this->memberTwo = User::factory()->create(['department_id' => null, 'name' => 'Member Two']);
        $this->memberTwo->assignRole(Role::Member->value);

        $postFrom = Cadre::query()->create([
            'department_id' => $department->id, 'name' => 'Under Secretary',
            'class_level' => 'I', 'status' => 'active',
        ]);
        $postTo = Cadre::query()->create([
            'department_id' => $department->id, 'name' => 'Deputy Secretary',
            'class_level' => 'I', 'status' => 'active',
        ]);

        $this->proposal = DpcProposal::query()->create([
            'department_id' => $department->id,
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $postTo->id,
            'post_from_cadre_id' => $postFrom->id,
            'proposal_date' => '2026-06-25',
            'status' => DpcProposalStatus::Approved->value,
            'dpc_code' => 'HE202600001',
            'forwarded_at' => now(),
        ]);

        // US3 from the PPT's own worked example (S33): six CR grades whose
        // five most recent sum to 14, and no tribal-area service recorded.
        $this->seedUs3();

        app(AllotmentService::class)->allot(
            $this->proposal->fresh(), $this->memberOne, $this->chairman, request(), Carbon::parse('2026-07-10'),
        );
        $scheduling = app(SchedulingService::class);
        $scheduling->accept($this->proposal->fresh(), $this->memberOne, request());
        $scheduling->schedule($this->proposal->fresh(), $this->memberOne, request(), Carbon::parse('2026-07-12'), '14:00');

        $this->proceeding = app(ProceedingService::class)
            ->open($this->proposal->fresh(), $this->memberOne, request());
    }

    // ----------------------------------------------------------------- helpers

    private function seedUs3(): void
    {
        $row = $this->proposal->evaluationRows()->create([
            'sn' => 3,
            'name' => 'US3',
            'dob' => '1967-04-09',
            'qualification' => 'BA',
            'reservation_category_id' => ReservationCategory::query()->firstOrFail()->id,
            'tribal_area_service_years' => null,
        ]);

        foreach ([2012 => 'क', 2013 => 'ख', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क'] as $year => $grade) {
            $row->crGrades()->create(['year' => $year, 'grade' => $grade]);
            $row->loyaltyFlags()->create(['year' => $year, 'flag' => true]);
            $row->propertyFlags()->create(['year' => $year, 'flag' => true]);
        }
    }

    private function row(): DpcEvaluationRow
    {
        return DpcEvaluationRow::query()->where('sn', 3)->firstOrFail();
    }

    private function correct(array $payload = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->memberOne)
            ->post(route('cgpsc.proceedings.corrections.store', $this->proceeding), array_merge([
                'sn' => 3,
                'field' => CorrectableField::TribalAreaServiceYears->value,
                'year' => '',
                'new_value' => '3',
                'reason' => 'Tribal-area service confirmed from the department record at the sitting.',
            ], $payload));
    }

    private function service(): ProceedingCorrectionService
    {
        return app(ProceedingCorrectionService::class);
    }

    // ------------------------------------------------------------- recording

    public function test_the_conducting_member_can_record_a_tribal_correction(): void
    {
        $this->correct()->assertRedirect(route('cgpsc.proceedings.show', $this->proceeding))
            ->assertSessionHasNoErrors();

        $override = DpcProceedingOverride::query()->firstOrFail();
        $this->assertSame(CorrectableField::TribalAreaServiceYears->value, $override->field);
        $this->assertNull($override->previous_value);
        $this->assertSame('3', $override->new_value);
        $this->assertSame($this->memberOne->id, $override->applied_by);
        $this->assertSame('Member One', $override->applied_by_name);
        $this->assertNotEmpty($override->uuid);
    }

    public function test_a_correction_never_touches_the_frozen_evaluation_row(): void
    {
        // AQ-1, and the whole reason the override table exists.
        $before = $this->row()->tribal_area_service_years;
        $gradeBefore = $this->row()->crGrades->firstWhere('year', 2013)?->grade;

        $this->correct();
        $this->correct(['field' => CorrectableField::CrGrade->value, 'year' => '2013', 'new_value' => 'क']);

        $this->assertSame($before, $this->row()->fresh()->tribal_area_service_years);
        $this->assertSame($gradeBefore, $this->row()->fresh()->crGrades->firstWhere('year', 2013)?->grade);
    }

    public function test_a_correction_requires_a_reason(): void
    {
        $this->correct(['reason' => ''])->assertSessionHasErrors('reason');

        $this->assertSame(0, DpcProceedingOverride::query()->count());
    }

    public function test_a_one_word_reason_is_refused(): void
    {
        // A correction without a stated reason evidences nothing, and the
        // reason is what a tribunal reads.
        $this->correct(['reason' => 'typo'])->assertSessionHasErrors('reason');
    }

    public function test_an_unknown_field_cannot_be_corrected(): void
    {
        $this->correct(['field' => 'name'])->assertSessionHasErrors('field');

        $this->assertSame(0, DpcProceedingOverride::query()->count());
    }

    public function test_a_field_disabled_in_configuration_cannot_be_corrected(): void
    {
        config()->set('dpc.proceeding.correctable_fields', ['cr_grade']);

        $this->correct()->assertSessionHasErrors('field');
    }

    public function test_an_invalid_cr_grade_is_refused_by_m4s_own_rule(): void
    {
        // Validated through DpcRowRules, so manual entry, import and correction
        // cannot drift apart on what a valid grade is.
        $this->correct([
            'field' => CorrectableField::CrGrade->value, 'year' => '2013', 'new_value' => 'Z',
        ])->assertSessionHasErrors('new_value');
    }

    public function test_a_valid_cr_grade_is_accepted(): void
    {
        $this->correct([
            'field' => CorrectableField::CrGrade->value, 'year' => '2013', 'new_value' => 'क',
        ])->assertSessionHasNoErrors();

        $this->assertSame('क', DpcProceedingOverride::query()->firstOrFail()->new_value);
    }

    public function test_a_per_year_field_requires_a_year(): void
    {
        $this->correct([
            'field' => CorrectableField::CrGrade->value, 'year' => '', 'new_value' => 'क',
        ])->assertSessionHasErrors('year');
    }

    public function test_a_whole_row_field_refuses_a_year(): void
    {
        $this->correct(['year' => '2013'])->assertSessionHasErrors('year');
    }

    public function test_a_property_return_accepts_only_y_or_n(): void
    {
        $this->correct([
            'field' => CorrectableField::PropertyFlag->value, 'year' => '2016', 'new_value' => 'maybe',
        ])->assertSessionHasErrors('new_value');

        $this->correct([
            'field' => CorrectableField::PropertyFlag->value, 'year' => '2016', 'new_value' => 'N',
        ])->assertSessionHasNoErrors();
    }

    public function test_a_value_can_be_cleared(): void
    {
        // "Not recorded" is distinct from any grade, so a grade entered in
        // error must be removable.
        $this->correct([
            'field' => CorrectableField::CrGrade->value, 'year' => '2013', 'new_value' => '',
        ])->assertSessionHasNoErrors();

        $override = DpcProceedingOverride::query()->firstOrFail();
        $this->assertSame('ख', $override->previous_value);
        $this->assertNull($override->new_value);
    }

    public function test_recording_the_value_already_in_force_is_refused(): void
    {
        $this->expectException(WorkflowException::class);

        $this->service()->record(
            $this->proceeding, $this->memberOne, CorrectableField::CrGrade, 3, 2013, 'ख',
            'Recording the value that is already there.',
        );
    }

    // ------------------------------------------------------------ supersession

    public function test_a_second_correction_supersedes_the_first(): void
    {
        $this->correct(['new_value' => '2']);
        $this->correct(['new_value' => '3', 'reason' => 'Corrected again after checking the service book.']);

        $this->assertSame(2, DpcProceedingOverride::query()->count());

        $inForce = DpcProceedingOverride::query()->inForce()->get();
        $this->assertCount(1, $inForce);
        $this->assertSame('3', $inForce->first()->new_value);
        // The second records what the first had made it, not the department's
        // original — so a chain reads back step by step.
        $this->assertSame('2', $inForce->first()->previous_value);
    }

    public function test_corrections_to_different_years_do_not_supersede_each_other(): void
    {
        $this->correct(['field' => CorrectableField::CrGrade->value, 'year' => '2013', 'new_value' => 'क']);
        $this->correct(['field' => CorrectableField::CrGrade->value, 'year' => '2014', 'new_value' => 'ख']);

        $this->assertCount(2, DpcProceedingOverride::query()->inForce()->get());
    }

    // -------------------------------------------------------------- withdrawal

    public function test_withdrawing_a_correction_restores_the_previous_value_without_deleting(): void
    {
        $this->correct(['new_value' => '3']);
        $override = DpcProceedingOverride::query()->firstOrFail();

        $this->actingAs($this->memberOne)
            ->delete(route('cgpsc.proceedings.corrections.destroy', [$this->proceeding, $override]), [
                'reason' => 'Recorded against the wrong officer at the sitting.',
            ])
            ->assertSessionHasNoErrors();

        // Nothing deleted; the withdrawal is itself an appended correction.
        $this->assertSame(2, DpcProceedingOverride::query()->count());
        $this->assertNotNull($override->fresh()->superseded_by_id);

        $reverting = DpcProceedingOverride::query()->inForce()->firstOrFail();
        $this->assertSame('3', $reverting->previous_value);
        $this->assertNull($reverting->new_value);
    }

    public function test_withdrawal_requires_a_reason(): void
    {
        $this->correct();
        $override = DpcProceedingOverride::query()->firstOrFail();

        $this->actingAs($this->memberOne)
            ->delete(route('cgpsc.proceedings.corrections.destroy', [$this->proceeding, $override]), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(1, DpcProceedingOverride::query()->count());
    }

    public function test_an_already_superseded_correction_cannot_be_withdrawn(): void
    {
        $this->correct(['new_value' => '2']);
        $first = DpcProceedingOverride::query()->firstOrFail();
        $this->correct(['new_value' => '3', 'reason' => 'Corrected again after checking the service book.']);

        $this->expectException(WorkflowException::class);
        $this->service()->withdraw($this->proceeding, $this->memberOne, $first->fresh(), 'Withdrawing the old one.');
    }

    // ---------------------------------------------------------- effective chart

    public function test_the_effective_chart_applies_corrections_without_losing_the_original(): void
    {
        $this->correct(['field' => CorrectableField::CrGrade->value, 'year' => '2013', 'new_value' => 'क']);

        $chart = $this->service()->effectiveChart($this->proceeding->fresh());
        $cell = $chart[0]['cr'][2013];

        $this->assertSame('ख', $cell['submitted']);   // what the department sent
        $this->assertSame('क', $cell['effective']);   // what is in force now
        $this->assertTrue($cell['corrected']);
    }

    public function test_uncorrected_cells_report_the_department_value(): void
    {
        $chart = $this->service()->effectiveChart($this->proceeding->fresh());
        $cell = $chart[0]['cr'][2014];

        $this->assertSame('क', $cell['submitted']);
        $this->assertSame('क', $cell['effective']);
        $this->assertFalse($cell['corrected']);
    }

    public function test_a_withdrawn_correction_leaves_the_chart_as_the_department_sent_it(): void
    {
        $this->correct(['field' => CorrectableField::CrGrade->value, 'year' => '2013', 'new_value' => 'क']);
        $override = DpcProceedingOverride::query()->firstOrFail();

        $this->service()->withdraw($this->proceeding, $this->memberOne, $override, 'Recorded in error at the sitting.');

        $cell = $this->service()->effectiveChart($this->proceeding->fresh())[0]['cr'][2013];
        $this->assertSame('ख', $cell['effective']);
    }

    // ------------------------------------------------------- timeline & audit

    public function test_a_correction_is_recorded_on_the_timeline_replayably(): void
    {
        $this->correct(['new_value' => '3']);

        $entry = ActivityTimeline::query()->where('event', 'dpc_proceeding.correction')->firstOrFail();
        $p = $entry->properties;

        // Directive 2 — everything needed to replay the correction, without
        // reading a business table.
        $this->assertSame($this->proceeding->uuid, $p['proceeding_uuid']);
        $this->assertSame(3, $p['sn']);
        $this->assertSame('US3', $p['officer_name']);
        $this->assertSame(CorrectableField::TribalAreaServiceYears->value, $p['field']);
        $this->assertNull($p['previous_value']);
        $this->assertSame('3', $p['new_value']);
        $this->assertNotEmpty($p['reason']);
        $this->assertSame('Member One', $p['applied_by_name']);
        $this->assertNotEmpty($p['override_uuid']);
        $this->assertSame('Member One', $entry->actor_name);
    }

    public function test_a_superseding_correction_names_what_it_superseded(): void
    {
        $this->correct(['new_value' => '2']);
        $this->correct(['new_value' => '3', 'reason' => 'Corrected again after checking the service book.']);

        $entries = ActivityTimeline::query()
            ->where('event', 'dpc_proceeding.correction')->orderBy('id')->get();

        $this->assertArrayNotHasKey('superseded_override_uuid', $entries[0]->properties);
        $this->assertNotEmpty($entries[1]->properties['superseded_override_uuid']);
    }

    public function test_correction_history_is_read_from_the_timeline_alone(): void
    {
        $this->correct(['new_value' => '3']);
        $override = DpcProceedingOverride::query()->firstOrFail();
        $this->service()->withdraw($this->proceeding, $this->memberOne, $override, 'Recorded in error at the sitting.');

        $entries = $this->service()->historyFromTimeline($this->proceeding->fresh());

        $this->assertCount(2, $entries);
        $this->assertSame('dpc_proceeding.correction', $entries[0]->event);
        $this->assertSame('dpc_proceeding.correction_withdrawn', $entries[1]->event);
    }

    public function test_a_correction_is_not_a_workflow_transition(): void
    {
        // It changes no state — the DPC is in exactly the same place before
        // and after — so the ledger must not claim one (Directive 4).
        $before = WorkflowTransition::query()->count();

        $this->correct();

        $this->assertSame($before, WorkflowTransition::query()->count());
        $this->assertSame('in_proceeding', app(ProceedingService::class)->state($this->proposal->fresh()));
    }

    public function test_a_correction_is_audited_and_carries_the_proceeding_uuid(): void
    {
        // Directive 3 — the proceeding UUID must appear in the audit trail,
        // which keys on a sequential id that never leaves the database.
        $this->correct();

        $audit = Audit::query()
            ->where('auditable_type', (new DpcProceedingOverride)->getMorphClass())
            ->firstOrFail();

        $this->assertStringContainsString($this->proceeding->uuid, (string) $audit->tags);
    }

    public function test_the_proceeding_itself_is_audit_tagged_with_its_uuid(): void
    {
        $audit = Audit::query()
            ->where('auditable_type', (new DpcProceeding)->getMorphClass())
            ->firstOrFail();

        $this->assertStringContainsString($this->proceeding->uuid, (string) $audit->tags);
    }

    // ------------------------------------------------------------- permissions

    public function test_another_member_cannot_record_a_correction(): void
    {
        $this->correct([], $this->memberTwo)->assertForbidden();

        $this->assertSame(0, DpcProceedingOverride::query()->count());
    }

    public function test_the_chairman_cannot_correct_a_sitting_he_is_not_conducting(): void
    {
        // AQ-6 — reading is not conducting.
        $this->correct([], $this->chairman)->assertForbidden();

        $this->assertSame(0, DpcProceedingOverride::query()->count());
    }

    public function test_a_concluded_proceeding_cannot_be_corrected(): void
    {
        $this->proceeding->update(['status' => DpcProceedingStatus::ResultApproved->value]);

        $this->correct()->assertForbidden();

        $this->assertSame(0, DpcProceedingOverride::query()->count());
    }

    public function test_the_service_refuses_a_correction_on_a_concluded_proceeding(): void
    {
        $this->proceeding->update(['status' => DpcProceedingStatus::ResultApproved->value]);

        $this->expectException(WorkflowException::class);
        $this->service()->record(
            $this->proceeding->fresh(), $this->memberOne, CorrectableField::TribalAreaServiceYears, 3, null, '3',
            'Attempting a correction after the sitting concluded.',
        );
    }

    public function test_a_correction_cannot_be_recorded_against_another_proceedings_override(): void
    {
        $other = DpcProceeding::query()->create([
            'dpc_proposal_id' => $this->proposal->id,
            'status' => DpcProceedingStatus::Cancelled->value,
            'conducted_by' => $this->memberOne->id,
            'conducted_by_name' => $this->memberOne->name,
            'proposal_version' => str_repeat('b', 64),
            'opened_at' => now(),
        ]);

        $this->correct();
        $override = DpcProceedingOverride::query()->firstOrFail();

        $this->expectException(WorkflowException::class);
        $this->service()->withdraw($other, $this->memberOne, $override, 'Withdrawing from the wrong sitting.');
    }

    public function test_an_unknown_serial_number_is_refused(): void
    {
        $this->expectException(WorkflowException::class);

        $this->service()->record(
            $this->proceeding, $this->memberOne, CorrectableField::TribalAreaServiceYears, 99, null, '3',
            'Correcting an officer who is not on this chart.',
        );
    }

    // ------------------------------------------------------------------ screens

    public function test_the_workspace_shows_the_correction_beside_the_original(): void
    {
        $this->correct(['field' => CorrectableField::CrGrade->value, 'year' => '2013', 'new_value' => 'क']);

        $this->actingAs($this->memberOne)
            ->get(route('cgpsc.proceedings.show', $this->proceeding))
            ->assertOk()
            ->assertSee('Corrections in Force')
            ->assertSee('<del class="text-muted">ख</del>', false);
    }

    public function test_the_chairman_sees_the_workspace_without_edit_controls(): void
    {
        $response = $this->actingAs($this->chairman)
            ->get(route('cgpsc.proceedings.show', $this->proceeding))
            ->assertOk();

        $response->assertDontSee('>SAVE<', false);
    }

    public function test_the_correction_history_screen_renders(): void
    {
        $this->correct();

        $this->actingAs($this->memberOne)
            ->get(route('cgpsc.proceedings.corrections.index', $this->proceeding))
            ->assertOk()
            ->assertSee('Correction History')
            ->assertSee('US3');
    }
}
