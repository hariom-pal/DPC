<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\EvaluationDocumentKind;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use App\Domain\Eligibility\ValueObjects\OfficerRecord;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\SealedCoverReason;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * Coder Instructions rule 2.2 — departmental enquiry and the sealed cover: the
 * Administrator's Sealed Cover Reason master, and the Authorized Officer's answer for
 * every officer in Employee Details (2026-10-21).
 */
class SealedCoverDetailsTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private User $admin;

    private User $ao;

    private DpcProposal $proposal;

    private DpcEvaluationRow $row;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);

        $department = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($department->id, Role::AuthorizedOfficer)->create();
        $from = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Block Education Officer', 'class_level' => 'II', 'status' => 'active']);
        $to = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Additional Director', 'class_level' => 'I', 'status' => 'active']);

        $this->proposal = $this->raiseDpc($this->ao, $from, $to);
        app(DpcProposalService::class)->saveSeniorityEntries($this->proposal, [
            ['seniority_number' => 1, 'employee_number' => 'E-1', 'name' => 'Officer One', 'dob' => '1970-03-20', 'date_of_joining_service' => '1995-07-01', 'promotion_from_post' => 'Block Education Officer'],
        ], $this->ao, $this->proposal->seniorityReferenceDate());
        $this->row = $this->proposal->evaluationRows()->sole();
    }

    private function reason(string $code): SealedCoverReason
    {
        return SealedCoverReason::query()->where('code', $code)->firstOrFail();
    }

    /** With the two questions of 2026-11-14 answered No, each with its document. */
    private function answer(array $data): TestResponse
    {
        return $this->actingAs($this->ao)->put(route('dpc-proposals.employees.vigilance', [$this->proposal, $this->row]), $data + [
            'enquiry_ongoing' => '0', 'punishment_effective' => '0', 'vigilance_verified' => '1',
            'enquiry_document' => UploadedFile::fake()->create('enquiry.pdf', 20, 'application/pdf'),
            'punishment_document' => UploadedFile::fake()->create('punishment.pdf', 20, 'application/pdf'),
        ]);
    }

    /**
     * @return list<string>
     */
    private function missing(): array
    {
        return app(EmployeeDetailsService::class)->status($this->row->refresh())['sections']['vigilance'];
    }

    private function uploadOrder(): TestResponse
    {
        return $this->actingAs($this->ao)->post(route('dpc-proposals.employees.documents.store', ['verified' => '1', $this->proposal, $this->row]), [
            'kind' => EvaluationDocumentKind::VigilanceOrder->value,
            'document' => UploadedFile::fake()->create('order.pdf', 20, 'application/pdf'),
        ]);
    }

    // ======================================================== the master

    public function test_the_four_rule_2_2_reasons_are_seeded_with_their_effects(): void
    {
        $this->assertSame([
            'de_chargesheet' => 'sealed_cover',
            'police_chargesheet' => 'sealed_cover',
            'show_cause_only' => 'no_effect',
            'suspended_no_chargesheet' => 'no_effect',
        ], SealedCoverReason::query()->orderBy('sort_order')->pluck('effect', 'code')->all());
    }

    public function test_the_administrator_maintains_the_reasons(): void
    {
        $this->actingAs($this->admin)->get(route('admin.sealed-cover-reasons.index'))
            ->assertOk()
            ->assertSee('Police charge-sheet filed before a court');

        $this->actingAs($this->admin)->post(route('admin.sealed-cover-reasons.store'), [
            'code' => 'court_conviction', 'name_en' => 'Convicted by a court', 'name_hi' => 'न्यायालय द्वारा दोषसिद्ध',
            'effect' => 'sealed_cover', 'requires_document' => '1', 'sort_order' => 50, 'status' => 'active',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.sealed-cover-reasons.index'));

        $this->assertTrue($this->reason('court_conviction')->sealsTheCover());

        $this->actingAs($this->admin)->post(route('admin.sealed-cover-reasons.store'), [
            'code' => 'bad', 'name_en' => 'x', 'name_hi' => 'x', 'effect' => 'promote_anyway',
            'requires_document' => '1', 'sort_order' => 1, 'status' => 'active',
        ])->assertSessionHasErrors('effect');

        // A department's officer does not maintain masters.
        $this->actingAs($this->ao)->get(route('admin.sealed-cover-reasons.index'))->assertForbidden();
    }

    // ================================================== the AO's answer

    public function test_every_officer_must_be_answered(): void
    {
        // The two questions; "is a matter pending?" is no longer asked (2026-11-18).
        $this->assertSame([
            __('dpc.employee_details.missing.enquiry_ongoing'),
            __('dpc.employee_details.missing.punishment_effective'),
        ], $this->missing());

        $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))
            ->assertOk()
            ->assertSee('id="section-vigilance"', false)
            ->assertDontSee(__('dpc.employee_details.vigilance.question'))
            ->assertDontSee('name="vigilance_pending"', false);

        $this->answer([])->assertSessionHasNoErrors();
        $this->assertSame([], $this->missing());
    }

    /**
     * 2026-11-18: the first question — a charge-sheet issued and the departmental
     * inquiry / court case ongoing — is the sealed cover of rule 2.2, not Unfit.
     */
    public function test_a_charge_sheet_with_the_inquiry_ongoing_is_the_sealed_cover(): void
    {
        $this->answer(['enquiry_ongoing' => '1'])->assertSessionHasNoErrors();
        $row = $this->row->fresh();

        $this->assertTrue($row->isUnderSealedCover());
        $this->assertSame([], $row->unfitReasons(), 'Sealed cover, not Unfit.');
        $this->assertSame('Charge-sheet issued and departmental inquiry / court case ongoing', OfficerRecord::fromEvaluationRow($row)->sealedCover);
        $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))
            ->assertSee('data-sealed-cover="yes"', false)->assertSee(__('dpc.employee_details.vigilance.sealed_cover'));

        // A punishment in effect is still Unfit.
        $this->answer(['enquiry_ongoing' => '0', 'punishment_effective' => '1'])->assertSessionHasNoErrors();
        $row = $this->row->fresh();
        $this->assertFalse($row->isUnderSealedCover());
        $this->assertCount(1, $row->unfitReasons());
    }

    /** A matter recorded pending before the question was retired is kept, and still seals. */
    public function test_a_pending_matter_recorded_before_is_kept(): void
    {
        $this->row->forceFill(['vigilance_pending' => true, 'sealed_cover_reason_id' => $this->reason('de_chargesheet')->id, 'vigilance_date' => '2025-06-01'])->save();

        $this->answer([])->assertSessionHasNoErrors();
        $row = $this->row->fresh();

        $this->assertTrue($row->vigilance_pending);
        $this->assertSame($this->reason('de_chargesheet')->id, $row->sealed_cover_reason_id);
        $this->assertTrue($row->isUnderSealedCover());
    }

    public function test_a_pending_matter_needs_its_reason_date_and_order(): void
    {
        $this->answer(['vigilance_pending' => '1'])->assertSessionHasErrors(['sealed_cover_reason_id', 'vigilance_date']);

        $this->answer([
            'vigilance_pending' => '1',
            'sealed_cover_reason_id' => $this->reason('de_chargesheet')->id,
            'vigilance_date' => '2025-06-01',
            'vigilance_remarks' => 'Charge-sheet no. 12/2025.',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($this->row->refresh()->isUnderSealedCover());
        $this->assertSame([__('dpc.employee_details.missing.vigilance_order')], $this->missing());

        $this->uploadOrder()->assertSessionHasNoErrors();
        $this->assertSame([], $this->missing());

        $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))
            ->assertSee('data-sealed-cover="yes"', false);
    }

    public function test_answering_nothing_pending_clears_the_details_and_refuses_an_order(): void
    {
        $this->answer([
            'vigilance_pending' => '1',
            'sealed_cover_reason_id' => $this->reason('show_cause_only')->id,
            'vigilance_date' => '2025-06-01',
        ])->assertSessionHasNoErrors();
        $this->assertFalse($this->row->refresh()->isUnderSealedCover(), 'A show-cause notice alone does not seal the cover.');

        $this->answer(['vigilance_pending' => '0', 'sealed_cover_reason_id' => $this->reason('de_chargesheet')->id])->assertSessionHasNoErrors();
        $this->row->refresh();
        $this->assertSame([false, null, null], [$this->row->vigilance_pending, $this->row->sealed_cover_reason_id, $this->row->vigilance_date]);

        $this->uploadOrder()->assertSessionHasErrors('vigilance');
    }
}
