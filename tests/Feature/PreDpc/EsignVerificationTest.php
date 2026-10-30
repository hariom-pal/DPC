<?php

declare(strict_types=1);

namespace Tests\Feature\PreDpc;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\PreDpc\Services\PreDpcProposalService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Domain\Signature\Models\Signature;
use App\Enums\MasterStatus;
use App\Enums\Role;
use App\Integrations\Contracts\SignatureProvider;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CompletesDpcProposals;
use Tests\Concerns\PrescribesQualifications;
use Tests\Concerns\RaisesDpcProposals;
use Tests\Support\FakeSignatureProvider;
use Tests\TestCase;

/**
 * The AO's eSign on submitting a Pre-DPC and the AH's on approving (2026-10-27):
 * verified against each eSign transaction — the provider's authenticated signer
 * name against the portal name, the request reference and document digest, a
 * transaction never seen before, and the current allotment — every time.
 *
 * The provider here is a fake: these tests prove the portal's handling of an
 * answer, not that any real eSign provider gives one.
 */
class EsignVerificationTest extends TestCase
{
    use CompletesDpcProposals;
    use PrescribesQualifications;
    use RaisesDpcProposals;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $ah;

    private FakeSignatureProvider $esign;

    private PreDpcProposalService $preDpcs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, LookupMasterSeeder::class]);

        $this->department = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create(['name' => 'Shri Ramesh Kumar Verma']);
        $this->ah = User::factory()->assignedTo($this->department, Role::AdministrativeHead)->create(['name' => 'Smt. Sunita Sharma']);

        $this->esign = new FakeSignatureProvider;
        $this->app->instance(SignatureProvider::class, $this->esign);
        $this->preDpcs = app(PreDpcProposalService::class);
    }

    private function draft(string $post = 'Deputy Director'): PreDpcProposal
    {
        $target = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => $post, 'class_level' => 'I', 'status' => MasterStatus::Active->value]);
        $feeder = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Feeder of '.$post, 'class_level' => 'I', 'status' => MasterStatus::Active->value]);

        $proposal = $this->preDpcs->createDraft($this->department, $this->ao, [
            'dpc_year' => 2026,
            'promotion_to_post_id' => $target->id,
            'direct_recruitment_pct' => 0,
            'promotion_recruitment_pct' => 100,
            'promotion_sources' => [[
                'promotion_from_post_id' => $feeder->id, 'promotion_percentage' => 100,
                'min_experience_years' => 5, 'experience_unit' => 'years', 'qualification_ids' => $this->qualificationIds(),
            ]],
            'government_order_number' => 'GO/2026/SE/1',
            'effective_from' => '2026-01-01',
        ]);
        app(ProposalAllotmentService::class)->allot($proposal, $this->ao, $this->ah, null);

        return $proposal->refresh();
    }

    private function submit(PreDpcProposal $proposal, ?User $as = null): void
    {
        $this->preDpcs->submit($proposal->fresh(), $as ?? $this->ao, Request::create('/', 'POST', ['declaration' => '1']));
    }

    private function approve(PreDpcProposal $proposal, ?User $as = null): void
    {
        $this->preDpcs->approve($proposal->fresh(), $as ?? $this->ah, request());
    }

    /**
     * @param  callable(): void  $action
     */
    private function assertRefused(callable $action, string $reason): void
    {
        try {
            $action();
            $this->fail('The eSign was accepted.');
        } catch (WorkflowException $e) {
            $this->assertSame(__('esign.errors.'.$reason), $e->getMessage());
        }
    }

    // ------------------------------------------------------------- AO submission

    public function test_a_matching_name_submits_with_the_aos_verified_signature(): void
    {
        $proposal = $this->draft();
        $this->submit($proposal);

        $proposal->refresh();
        $this->assertSame(PreDpcStatus::Submitted, $proposal->status);
        $signature = Signature::query()->where('signable_id', $proposal->id)->where('user_id', $this->ao->id)->sole();
        $this->assertSame('Shri Ramesh Kumar Verma', $signature->signer_name);
        $this->assertNotNull($signature->request_reference);
        $transition = WorkflowTransition::query()->where('workflow', 'pre_dpc')->where('event', 'submit')->sole();
        $this->assertSame($signature->id, $transition->signature_id);
    }

    public function test_the_name_policy_ignores_case_spacing_full_stops_and_salutations_only(): void
    {
        $proposal = $this->draft();
        $this->esign->mode = FakeSignatureProvider::OTHER_NAME;
        $this->esign->assertedName = '  RAMESH   kumar verma. ';
        $this->submit($proposal);
        $this->assertSame(PreDpcStatus::Submitted, $proposal->fresh()->status);

        // Initials are a material difference.
        $other = $this->draft('Joint Director');
        $this->esign->assertedName = 'R. K. Verma';
        $this->assertRefused(fn () => $this->submit($other), 'name_mismatch');
    }

    public function test_a_mismatching_name_blocks_submission_and_keeps_the_draft(): void
    {
        $proposal = $this->draft();
        $this->esign->mode = FakeSignatureProvider::OTHER_NAME;

        $this->assertRefused(fn () => $this->submit($proposal), 'name_mismatch');

        $this->assertSame(PreDpcStatus::Draft, $proposal->fresh()->status, 'The draft stays a draft.');
        $this->assertSame(0, Signature::query()->count(), 'Nothing is marked signed.');
        $this->assertSame(0, WorkflowTransition::query()->where('event', 'submit')->count());
        $this->assertDatabaseHas('security_events', ['user_id' => $this->ao->id, 'event' => 'esign.name_mismatch']);
    }

    public function test_a_failed_esign_blocks_submission(): void
    {
        $proposal = $this->draft();
        $this->esign->mode = FakeSignatureProvider::FAIL;

        $this->assertRefused(fn () => $this->submit($proposal), 'esign_failed');

        $this->assertSame(PreDpcStatus::Draft, $proposal->fresh()->status);
        $this->assertSame(0, Signature::query()->count());
        $this->assertDatabaseHas('security_events', ['user_id' => $this->ao->id, 'event' => 'esign.esign_failed']);
    }

    public function test_an_earlier_verified_signature_never_authorises_a_later_one(): void
    {
        $proposal = $this->draft();
        $this->submit($proposal);
        $this->preDpcs->return($proposal->fresh(), $this->ah, 'Correct the order date', request());

        // Resubmission needs its own eSign, and this one does not match.
        $this->esign->mode = FakeSignatureProvider::OTHER_NAME;
        $calls = $this->esign->calls;
        $this->assertRefused(fn () => $this->submit($proposal), 'name_mismatch');

        $this->assertSame($calls + 1, $this->esign->calls, 'The provider was asked again.');
        $this->assertSame(PreDpcStatus::Returned, $proposal->fresh()->status);
        $this->assertSame(1, Signature::query()->count(), 'The first, valid signature stays.');
    }

    public function test_an_unassigned_or_replaced_ao_cannot_submit(): void
    {
        $proposal = $this->draft();
        $stranger = User::factory()->assignedTo($this->department, Role::AuthorizedOfficer)->create();

        $this->expectException(WorkflowException::class);
        try {
            $this->submit($proposal, $stranger);
        } finally {
            $this->assertSame(0, $this->esign->calls, 'Nothing is sent for signing.');

            // Replaced: the old AO is refused too.
            app(ProposalAllotmentService::class)->allot($proposal->fresh(), $stranger, $this->ah, null, 'Officer on leave');
            try {
                $this->submit($proposal, $this->ao);
            } catch (WorkflowException) {
                $this->assertSame(PreDpcStatus::Draft, $proposal->fresh()->status);
            }
        }
    }

    // --------------------------------------------------------------- AH approval

    public function test_a_matching_name_approves_and_keeps_the_aos_signature(): void
    {
        $proposal = $this->draft();
        $this->submit($proposal);
        $this->approve($proposal);

        $proposal->refresh();
        $this->assertSame(PreDpcStatus::Approved, $proposal->status);
        $this->assertSame(2, Signature::query()->where('signable_id', $proposal->id)->count());
        $this->assertSame('Smt. Sunita Sharma', Signature::query()->findOrFail($proposal->signature_id)->signer_name);
    }

    public function test_a_mismatching_name_blocks_approval_and_keeps_the_ao_signature(): void
    {
        $proposal = $this->draft();
        $this->submit($proposal);
        $this->esign->mode = FakeSignatureProvider::OTHER_NAME;

        $this->assertRefused(fn () => $this->approve($proposal), 'name_mismatch');

        $proposal->refresh();
        $this->assertSame(PreDpcStatus::Submitted, $proposal->status);
        $this->assertNull($proposal->signature_id);
        $this->assertSame([$this->ao->id], Signature::query()->pluck('user_id')->all(), 'Only the AO\'s valid signature.');
        $this->assertDatabaseHas('security_events', ['user_id' => $this->ah->id, 'event' => 'esign.name_mismatch']);
    }

    public function test_a_failed_esign_blocks_approval(): void
    {
        $proposal = $this->draft();
        $this->submit($proposal);
        $this->esign->mode = FakeSignatureProvider::FAIL;

        $this->assertRefused(fn () => $this->approve($proposal), 'esign_failed');
        $this->assertSame(PreDpcStatus::Submitted, $proposal->fresh()->status);
    }

    public function test_an_ahs_earlier_verified_approval_never_authorises_a_later_one(): void
    {
        $first = $this->draft();
        $this->submit($first);
        $this->approve($first);

        $second = $this->draft('Joint Director');
        $this->submit($second);
        $this->esign->mode = FakeSignatureProvider::OTHER_NAME;

        $this->assertRefused(fn () => $this->approve($second), 'name_mismatch');
        $this->assertSame(PreDpcStatus::Submitted, $second->fresh()->status);
    }

    public function test_an_unassigned_or_replaced_ah_cannot_approve(): void
    {
        $proposal = $this->draft();
        $this->submit($proposal);
        $other = User::factory()->assignedTo($this->department, Role::AdministrativeHead)->create();

        foreach ([$other] as $who) {
            try {
                $this->approve($proposal, $who);
                $this->fail('An unallotted AH approved.');
            } catch (WorkflowException $e) {
                $this->assertSame(__('esign.errors.not_current_officer'), $e->getMessage());
            }
        }

        $this->assertSame(PreDpcStatus::Submitted, $proposal->fresh()->status);
        $this->assertSame(1, $this->esign->calls, 'Only the AO\'s eSign was ever requested.');
    }

    public function test_the_dpc_approval_is_verified_the_same_way(): void
    {
        $from = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $to = DepartmentPost::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);
        $dpc = $this->raiseDpc($this->ao, $from, $to);
        $this->makeProposalSubmittable($dpc);
        app(DpcProposalService::class)->submit($dpc->fresh(), $this->ao, request());

        $this->esign->mode = FakeSignatureProvider::OTHER_NAME;
        $this->assertRefused(fn () => app(DpcProposalService::class)->approve($dpc->fresh(), $this->ah, request()), 'name_mismatch');
        $this->assertSame(DpcProposalStatus::Submitted, $dpc->fresh()->status);
        $this->assertDatabaseHas('security_events', ['user_id' => $this->ah->id, 'event' => 'esign.name_mismatch']);

        $this->esign->mode = FakeSignatureProvider::OK;
        app(DpcProposalService::class)->approve($dpc->fresh(), $this->ah, request());
        $this->assertSame(DpcProposalStatus::Approved, $dpc->fresh()->status);
    }

    // ----------------------------------------------------------------- integrity

    public function test_a_replayed_transaction_is_refused(): void
    {
        $this->esign->mode = FakeSignatureProvider::REPLAY;
        $first = $this->draft();
        $this->submit($first);

        $second = $this->draft('Joint Director');
        $this->assertRefused(fn () => $this->submit($second), 'replayed');
        $this->assertSame(PreDpcStatus::Draft, $second->fresh()->status);
        $this->assertSame(1, Signature::query()->count());
    }

    public function test_a_response_for_another_request_or_document_is_refused(): void
    {
        $proposal = $this->draft();

        foreach ([FakeSignatureProvider::WRONG_REFERENCE, FakeSignatureProvider::WRONG_DOCUMENT] as $mode) {
            $this->esign->mode = $mode;
            $this->assertRefused(fn () => $this->submit($proposal), 'not_correlated');
        }

        $this->assertSame(PreDpcStatus::Draft, $proposal->fresh()->status);
        $this->assertSame(0, Signature::query()->count());
    }

    public function test_a_browser_supplied_name_is_never_read(): void
    {
        $proposal = $this->draft();
        $this->esign->mode = FakeSignatureProvider::OTHER_NAME;

        // Whatever the request carries, the provider's name decides.
        request()->merge(['signer_name' => 'Shri Ramesh Kumar Verma']);
        $this->assertRefused(fn () => $this->submit($proposal), 'name_mismatch');
    }
}
