<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ApprovesAsSecretary;
use Tests\Concerns\CompletesDpcProposals;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * The Secretary, CGPSC (business decision, 2026-11-14): after the AH approves a DPC
 * it waits for the Secretary — every department's — who approves it with an eSign
 * and forwards it to the Chairman, or returns it to the AO with a reason and a
 * supporting document. The AO cannot take it back while it is with the Secretary.
 */
class SecretaryApprovalTest extends TestCase
{
    use ApprovesAsSecretary;
    use CompletesDpcProposals;
    use RaisesDpcProposals;
    use RefreshDatabase;

    private User $ao;

    private User $ah;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        Storage::fake('local');
        config(['features.csc_digisign' => false]);

        $department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($department->id, Role::AuthorizedOfficer)->create();
        $this->ah = User::factory()->assignedTo($department->id, Role::AdministrativeHead)->create();

        $from = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $to = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);
        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), $this->dpcStorePayload($this->sanctioningPreDpc($from->id, $to->id)));
        $this->proposal = DpcProposal::query()->latest('id')->firstOrFail();

        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal))->assertSessionHasNoErrors();
        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $this->proposal))->assertSessionHasNoErrors();
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('reason.pdf', 20, 'application/pdf');
    }

    public function test_the_ahs_approval_waits_for_the_secretary_not_the_chairman(): void
    {
        $this->proposal->refresh();
        $this->assertSame(DpcProposalStatus::PendingSecretary, $this->proposal->status);
        $this->assertNull($this->proposal->forwarded_at);

        $chairman = User::factory()->create();
        $chairman->assignRole(Role::Chairman->value);
        $this->actingAs($chairman)->get(route('cgpsc.allotments.index'))->assertOk()->assertDontSee($this->proposal->dpc_code);

        // The Secretary's queue lists it, from any department; the department's officers cannot reach the queue.
        $this->actingAs($this->secretary())->get(route('secretary.index'))->assertOk()
            ->assertSee('data-secretary-pending="'.$this->proposal->uuid.'"', false);
        $this->actingAs($this->ah)->get(route('secretary.index'))->assertForbidden();
        $this->actingAs($this->ao)->get(route('secretary.index'))->assertForbidden();

        // The Secretary reads the whole DPC on the AH's review screens and decides on the last.
        $this->actingAs($this->secretary())->get(route('dpc-proposals.show', $this->proposal))->assertOk()->assertSee('id="secretary-review"', false);
        $this->actingAs($this->secretary())->get(route('dpc-proposals.review', [$this->proposal, 5]))->assertOk()
            ->assertSee(__('dpc.secretary.review_heading'))
            ->assertSee('id="dpc-secretary-approve"', false)
            ->assertSee('id="secretary-return"', false);
    }

    public function test_the_secretarys_esigned_approval_forwards_the_dpc_to_the_chairman(): void
    {
        // Only the Secretary decides.
        $this->actingAs($this->ah)->get(route('esign.show', ['kind' => 'dpc-secretary-approve', 'uuid' => $this->proposal->uuid]))->assertForbidden();

        $this->approveAsSecretary($this->proposal);

        $this->proposal->refresh();
        $this->assertSame(DpcProposalStatus::Approved, $this->proposal->status);
        $this->assertNotNull($this->proposal->forwarded_at);
        $this->assertSame($this->secretary()->id, $this->proposal->secretary_approved_by);
        $this->assertNotNull($this->proposal->secretary_signature_id);
        $this->assertSame($this->ah->id, $this->proposal->approved_by, 'The AH\'s approval keeps its own record.');

        $chairman = User::factory()->create();
        $chairman->assignRole(Role::Chairman->value);
        $this->actingAs($chairman)->get(route('cgpsc.allotments.index'))->assertOk()->assertSee($this->proposal->dpc_code);

        // Decided once.
        $this->actingAs($this->secretary())->get(route('esign.show', ['kind' => 'dpc-secretary-approve', 'uuid' => $this->proposal->uuid]))->assertForbidden();
    }

    public function test_a_return_needs_the_reason_and_a_supporting_document_and_goes_to_the_ao(): void
    {
        $this->actingAs($this->secretary())->post(route('secretary.return', $this->proposal), [])
            ->assertSessionHasErrors(['return_remarks', 'return_document']);
        $this->post(route('secretary.return', $this->proposal), ['return_remarks' => 'Seniority list is not attested.'])
            ->assertSessionHasErrors('return_document');
        $this->assertSame(DpcProposalStatus::PendingSecretary, $this->proposal->fresh()->status);

        // The AH cannot use the Secretary's return.
        $this->actingAs($this->ah)->post(route('secretary.return', $this->proposal), ['return_remarks' => 'x', 'return_document' => $this->pdf()])->assertForbidden();

        $revision = (int) $this->proposal->fresh()->revision_number;
        $this->actingAs($this->secretary())->post(route('secretary.return', $this->proposal), [
            'return_remarks' => 'Seniority list is not attested.', 'return_document' => $this->pdf(),
        ])->assertRedirect(route('secretary.index'))->assertSessionHasNoErrors();

        $proposal = $this->proposal->fresh();
        $this->assertSame(DpcProposalStatus::Returned, $proposal->status);
        $this->assertSame('Seniority list is not attested.', $proposal->return_remarks);
        $this->assertSame($revision + 1, (int) $proposal->revision_number);

        $returned = $proposal->revisions()->where('revision_number', $revision)->sole();
        $this->assertTrue($returned->returnedBySecretary());
        $this->assertSame($this->secretary()->id, $returned->returned_by);
        Storage::disk('local')->assertExists((string) $returned->return_document_path);

        // The AO sees why, with the document, and corrects it.
        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $proposal))->assertOk()
            ->assertSee('data-returned-by-secretary', false)
            ->assertSee(route('dpc-proposals.revisions.return-document', [$proposal, $returned]), false)
            // The AH's earlier approval is not shown as if it still stood (2026-11-15).
            ->assertDontSee('forwarded to CGPSC');
        $this->get(route('dpc-proposals.revisions.return-document', [$proposal, $returned]))->assertOk();
        $this->assertTrue($proposal->status->isEditable());
    }

    public function test_the_ao_cannot_take_the_dpc_back_while_it_is_with_the_secretary(): void
    {
        $this->actingAs($this->ao)->post(route('dpc-proposals.recall', $this->proposal), ['recall_remarks' => 'Correct a CR.'])
            ->assertSessionHasErrors('recall');
        $this->assertSame(DpcProposalStatus::PendingSecretary, $this->proposal->fresh()->status);

        // Once the Secretary has forwarded it, the AO's recall is as before.
        $this->approveAsSecretary($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.recall', $this->proposal), ['recall_remarks' => 'Correct a CR.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(DpcProposalStatus::Returned, $this->proposal->fresh()->status);
    }

    public function test_a_returned_dpc_goes_through_the_ah_before_the_secretary_again(): void
    {
        $this->actingAs($this->secretary())->post(route('secretary.return', $this->proposal), ['return_remarks' => 'Attest the list.', 'return_document' => $this->pdf()])
            ->assertSessionHasNoErrors();

        // Resubmitted, it is with the AH again — not yet the Secretary's.
        $this->makeProposalSubmittable($this->proposal->fresh());
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal))->assertSessionHasNoErrors();
        $this->assertSame(DpcProposalStatus::Submitted, $this->proposal->fresh()->status);
        $this->actingAs($this->secretary())->post(route('secretary.return', $this->proposal), ['return_remarks' => 'x', 'return_document' => $this->pdf()])->assertForbidden();
        $this->actingAs($this->secretary())->get(route('esign.show', ['kind' => 'dpc-secretary-approve', 'uuid' => $this->proposal->uuid]))->assertForbidden();

        $this->actingAs($this->ah)->post(route('dpc-proposals.approve', $this->proposal))->assertSessionHasNoErrors();
        $this->assertSame(DpcProposalStatus::PendingSecretary, $this->proposal->fresh()->status);
    }

    public function test_the_role_is_a_commission_role_the_administrator_can_give(): void
    {
        $this->assertContains(Role::Secretary, Role::commission());
        $this->assertContains(Role::Secretary, Role::provisionable());
        $this->assertFalse(Role::Secretary->isDepartmental());
        $this->assertSame('Secretary, CGPSC', Role::Secretary->label());
    }
}
