<?php

declare(strict_types=1);

namespace Tests\Feature\PreDpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\VacancyPositionStatus;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\Services\VacancyMasterService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\PayScale;
use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Models\Signature;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\OpensPreDpcs;
use Tests\Concerns\PrescribesQualifications;
use Tests\TestCase;

/**
 * The Pre-DPC and its Vacancy Management record go to the AH together (business
 * rules of 2026-11-19): prepared side by side, submitted under one eSign whose
 * document carries the vacancy figures, approved or returned by one decision, and
 * refused when the figures changed after the AO signed.
 */
class CombinedPreDpcVacancyTest extends TestCase
{
    use OpensPreDpcs;
    use PrescribesQualifications;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $ah;

    private DepartmentPost $fromPost;

    private DepartmentPost $toPost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        config(['dpc.pre_dpc_submission.with_vacancy' => true]);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create();
        $this->ah = User::factory()->assignedTo($this->department, Role::AdministrativeHead)->create();

        $scale = PayScale::query()->create(['code' => 'L12', 'label' => 'Level-12', 'minimum_pay' => 78800, 'status' => MasterStatus::Active->value]);
        $feeder = PayScale::query()->create(['code' => 'L10', 'label' => 'Level-10', 'minimum_pay' => 56100, 'status' => MasterStatus::Active->value]);
        $this->fromPost = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I',
            'pay_scale_id' => $feeder->id, 'promotion_duration_years' => 5, 'retirement_age' => 62, 'status' => MasterStatus::Active->value,
        ]);
        $this->toPost = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I',
            'pay_scale_id' => $scale->id, 'retirement_age' => 62, 'status' => MasterStatus::Active->value,
        ]);
    }

    private function draft(): PreDpcProposal
    {
        $this->storePreDpc($this->ao, [
            'declaration' => '1', 'dpc_year' => 2026, 'promotion_to_post_id' => $this->toPost->id,
            'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
            'promotion_sources' => [[
                'promotion_from_post_id' => $this->fromPost->id, 'promotion_percentage' => 100,
                'min_experience_years' => 5, 'experience_unit' => 'years', 'qualification_ids' => $this->qualificationIds(),
            ]],
            'government_order_number' => 'GO/2026/HE/1', 'effective_from' => '2026-01-01',
        ], $this->ah);

        return PreDpcProposal::query()->latest('id')->firstOrFail();
    }

    private function vacancies(): VacancyMasterService
    {
        return app(VacancyMasterService::class);
    }

    /** The record of the Pre-DPC's post and year, sanctioned 8, working 6 (2 vacancies). */
    private function prepareVacancy(bool $complete = true): VacancyPosition
    {
        $position = $this->vacancies()->openDraft($this->department, $this->toPost, 2026, $this->ao);
        $this->vacancies()->saveSanctionedStrength($position, ['sanctioned_total' => 8, 'order_number' => 'SANC/2026', 'order_date' => '2026-01-02'], $this->pdf(), $this->ao);

        if ($complete) {
            $this->vacancies()->saveWorkingStrength($position->fresh(), ['working_total' => 6, 'order_number' => 'EST/2026', 'order_date' => '2026-01-02'], $this->pdf(), $this->ao);
        }

        return $position->fresh();
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('order.pdf', 20, 'application/pdf');
    }

    private function submit(PreDpcProposal $proposal): TestResponse
    {
        return $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal), ['declaration' => '1']);
    }

    private function signedDocument(PreDpcProposal $proposal, string $action): string
    {
        $suffix = $action === 'submit' ? '-submission.txt' : "-v{$proposal->version}.txt";
        $signature = Signature::query()->where('signable_id', $proposal->id)->where('signable_type', $proposal->getMorphClass())
            ->latest('id')->get()->first(fn (Signature $s): bool => str_ends_with((string) $s->signed_path, $suffix));
        $this->assertNotNull($signature, 'Signed: '.$action);

        return (string) Storage::disk($signature->signed_disk)->get($signature->signed_path);
    }

    public function test_the_pre_dpc_is_not_submitted_without_a_complete_vacancy_record(): void
    {
        $proposal = $this->draft();

        $this->submit($proposal)->assertSessionHasErrors();
        $this->assertSame(PreDpcStatus::Draft, $proposal->fresh()->status);
        $this->actingAs($this->ao)->get(route('pre-dpc.show', $proposal))->assertSee(__('vacancymaster.combined.not_prepared', ['year' => 2026]));

        // Incomplete (no working strength): still refused.
        $this->prepareVacancy(complete: false);
        $this->submit($proposal)->assertSessionHasErrors();
        $this->assertSame(PreDpcStatus::Draft, $proposal->fresh()->status);
    }

    public function test_one_submission_carries_both_under_one_signed_document_with_a_snapshot(): void
    {
        $proposal = $this->draft();
        $position = $this->prepareVacancy();

        // Both drafts may be edited independently before the submission.
        $this->vacancies()->saveWorkingStrength($position, ['working_total' => 5, 'order_number' => 'EST/2026', 'order_date' => '2026-01-02', 'correction_reason' => 'Recounted.'], $this->pdf(), $this->ao);

        $this->submit($proposal)->assertSessionHasNoErrors();

        $proposal->refresh();
        $position->refresh();
        $this->assertSame(PreDpcStatus::Submitted, $proposal->status);
        $this->assertSame(VacancyPositionStatus::PendingApproval, $position->status);
        $this->assertSame($proposal->id, $position->submitted_with_pre_dpc_id);
        $this->assertSame(3, $position->submission_snapshot['available']);
        $this->assertSame(8, $position->submission_snapshot['sanctioned']);
        $this->assertSame(5, $position->submission_snapshot['working']);
        $this->assertSame(64, strlen((string) $position->submission_sha256));

        // The one document the AO signed carries the vacancy figures and their hash.
        $document = $this->signedDocument($proposal, 'submit');
        foreach (['Sanctioned Posts: 8', 'Working Strength: 5', 'Category A (clear vacancy): 3', 'Available Vacancies: 3', 'Promotion Quota: 3', (string) $position->submission_sha256] as $line) {
            $this->assertStringContainsString($line, $document);
        }

        // On its timeline, linked to the Pre-DPC.
        $this->assertSame($proposal->id, ActivityTimeline::query()->where('event', 'vacancy_position.submitted_with_pre_dpc')->sole()->properties['pre_dpc_proposal_id']);

        // While with the AH: no edit, no separate finalize or decision.
        $this->expectWorkflowError(fn () => $this->vacancies()->saveWorkingStrength($position, ['working_total' => 4, 'order_number' => 'EST/2026', 'order_date' => '2026-01-02'], $this->pdf(), $this->ao));
        $this->expectWorkflowError(fn () => $this->vacancies()->finalize($position, $this->ao));
        $this->expectWorkflowError(fn () => $this->vacancies()->approveRevision($position, $this->ah), __('vacancymaster.combined.decided_on_pre_dpc'));
        $this->expectWorkflowError(fn () => $this->vacancies()->returnRevision($position, 'no', $this->ah), __('vacancymaster.combined.decided_on_pre_dpc'));

        // A second submission is refused.
        $this->submit($proposal);
        $this->assertSame(1, VacancyPosition::query()->count());
        $this->assertSame(1, ActivityTimeline::query()->where('event', 'vacancy_position.submitted_with_pre_dpc')->count());
    }

    public function test_one_approve_finalizes_both_and_a_second_does_nothing(): void
    {
        $proposal = $this->draft();
        $position = $this->prepareVacancy();
        $this->submit($proposal)->assertSessionHasNoErrors();

        // The AH sees one task for both.
        $this->actingAs($this->ah)->get(route('dashboard'))->assertOk()
            ->assertSee('data-pending-pre-dpc="'.$proposal->uuid.'"', false)
            ->assertSee(__('predpc.pending.ah_decide'))
            ->assertSee('data-pending-vacancy="pending_approval"', false);
        $this->actingAs($this->ah)->get(route('pre-dpc.show', $proposal))->assertSee('id="pre-dpc-approve"', false);

        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal))->assertSessionHasNoErrors();

        $this->assertSame(PreDpcStatus::Approved, $proposal->fresh()->status);
        $position->refresh();
        $this->assertSame(VacancyPositionStatus::Finalized, $position->status);
        $this->assertSame(2, (int) $position->breakdown['total_vacancy']);
        $this->assertSame($this->ah->id, $position->finalized_by);
        $this->assertStringContainsString('Available Vacancies: 2', $this->signedDocument($proposal, 'approve'));
        $this->assertSame(1, ActivityTimeline::query()->where('event', 'vacancy_position.approved_with_pre_dpc')->count());

        // Again: refused, nothing finalized twice.
        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal));
        $this->assertSame(1, ActivityTimeline::query()->where('event', 'vacancy_position.approved_with_pre_dpc')->count());
        $this->assertSame(1, VacancyPosition::query()->where('status', VacancyPositionStatus::Finalized->value)->count());
    }

    public function test_figures_changed_after_the_aos_signature_are_not_approved(): void
    {
        $proposal = $this->draft();
        $position = $this->prepareVacancy();
        $this->submit($proposal)->assertSessionHasNoErrors();

        // The sanctioned strength altered behind the record's back.
        DB::table('vacancy_position_sanctioned_strengths')->where('id', $position->fresh()->sanctionedStrength->id)->update(['sanctioned_total' => 9]);

        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal))->assertSessionHasErrors();
        $this->assertSame(PreDpcStatus::Submitted, $proposal->fresh()->status);
        $this->assertSame(VacancyPositionStatus::PendingApproval, $position->fresh()->status);
    }

    public function test_one_return_sends_both_back_and_the_ao_resubmits(): void
    {
        $proposal = $this->draft();
        $position = $this->prepareVacancy();
        $this->submit($proposal)->assertSessionHasNoErrors();

        $this->actingAs($this->ah)->post(route('pre-dpc.return', $proposal), ['return_remarks' => 'Working strength is wrong.'])->assertSessionHasNoErrors();

        $this->assertSame(PreDpcStatus::Returned, $proposal->fresh()->status);
        $position->refresh();
        $this->assertSame(VacancyPositionStatus::Draft, $position->status);
        $this->assertSame('Working strength is wrong.', $position->return_remarks);
        $this->assertSame('Working strength is wrong.', ActivityTimeline::query()->where('event', 'vacancy_position.returned_with_pre_dpc')->sole()->properties['remarks']);

        // The AO sees one task, with the remarks; the record says it was returned with the Pre-DPC.
        $this->actingAs($this->ao)->get(route('dashboard'))->assertSee(__('predpc.pending.ao_returned', ['remarks' => 'Working strength is wrong.']));

        // Corrected and submitted again: a fresh snapshot, then approved.
        $first = $position->submission_sha256;
        $this->vacancies()->saveWorkingStrength($position, ['working_total' => 7, 'order_number' => 'EST/2026', 'order_date' => '2026-01-02', 'correction_reason' => 'As returned.'], $this->pdf(), $this->ao);
        $this->submit($proposal)->assertSessionHasNoErrors();
        $position->refresh();
        $this->assertSame(VacancyPositionStatus::PendingApproval, $position->status);
        $this->assertNotSame($first, $position->submission_sha256);
        $this->assertSame(1, $position->submission_snapshot['available']);
        $this->assertNull($position->return_remarks);

        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal))->assertSessionHasNoErrors();
        $this->assertSame(VacancyPositionStatus::Finalized, $position->fresh()->status);
    }

    /** A Pre-DPC approved before this (or with the setting off) keeps the AO's finalize of its record. */
    public function test_a_record_of_an_already_approved_pre_dpc_keeps_the_aos_finalize(): void
    {
        config(['dpc.pre_dpc_submission.with_vacancy' => false]);
        $proposal = $this->draft();
        $this->submit($proposal)->assertSessionHasNoErrors();
        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal))->assertSessionHasNoErrors();
        config(['dpc.pre_dpc_submission.with_vacancy' => true]);

        $position = $this->prepareVacancy();
        $this->assertSame(VacancyPositionStatus::Draft, $position->status, 'Nothing approved it automatically.');

        $this->vacancies()->finalize($position, $this->ao);
        $this->assertSame(VacancyPositionStatus::Finalized, $position->fresh()->status);
    }

    public function test_a_record_whose_pre_dpc_is_pending_is_not_finalized_on_its_own(): void
    {
        $this->draft();
        $position = $this->prepareVacancy();

        $this->expectWorkflowError(fn () => $this->vacancies()->finalize($position, $this->ao));
        $this->assertSame(VacancyPositionStatus::Draft, $position->fresh()->status);
    }

    public function test_only_the_allotted_officers_of_the_department_submit_and_decide(): void
    {
        $proposal = $this->draft();
        $this->prepareVacancy();

        $other = Department::query()->create(['code' => 'PW', 'name' => 'Public Works', 'status' => 'active']);
        $outsiderAo = User::factory()->assignedTo($other, Role::AuthorizedOfficer)->create();
        $outsiderAh = User::factory()->assignedTo($other, Role::AdministrativeHead)->create();
        $unallottedAo = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create();

        $this->actingAs($outsiderAo)->post(route('pre-dpc.submit', $proposal), ['declaration' => '1']);
        $this->actingAs($unallottedAo)->post(route('pre-dpc.submit', $proposal), ['declaration' => '1']);
        $this->assertSame(PreDpcStatus::Draft, $proposal->fresh()->status);

        $this->submit($proposal)->assertSessionHasNoErrors();
        $this->actingAs($outsiderAh)->post(route('pre-dpc.approve', $proposal));
        $this->actingAs($this->ao)->post(route('pre-dpc.approve', $proposal));
        $this->assertSame(PreDpcStatus::Submitted, $proposal->fresh()->status);
        $this->assertSame(VacancyPositionStatus::PendingApproval, VacancyPosition::query()->sole()->status);

        // Nobody else sees the task.
        $this->actingAs($outsiderAh)->get(route('dashboard'))->assertDontSee('data-pending-pre-dpc="'.$proposal->uuid.'"', false);
    }

    private function expectWorkflowError(callable $action, ?string $message = null): void
    {
        try {
            $action();
        } catch (WorkflowException $e) {
            if ($message !== null) {
                $this->assertSame($message, $e->getMessage());
            }

            return;
        }

        $this->fail('Expected a WorkflowException.');
    }
}
