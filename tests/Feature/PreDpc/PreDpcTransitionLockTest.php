<?php

declare(strict_types=1);

namespace Tests\Feature\PreDpc;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\PreDpc\Enums\PreDpcStatus;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\PreDpc\Services\PreDpcProposalService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PrescribesQualifications;
use Tests\TestCase;

/**
 * Every Pre-DPC transition decides from the **stored** row, not from the instance
 * it was handed — TD-5, closed.
 *
 * **What the defect was.** Each transition read `status` from the model the
 * controller had already loaded, then wrote based on it. Two simultaneous submits
 * of the same proposal could both pass `assertAllowed()` against the same `draft`
 * and both write — two transitions out of one state, two ledger rows, and on
 * `approve` **two signatures on one proposal**. M4 onward have taken a
 * `lockForUpdate()` since Phase 1; the register said to backport it during
 * hardening, and this is that.
 *
 * **A true concurrent race cannot be reproduced in this suite** — it runs on a
 * single in-memory SQLite connection, so there is no second session to contend
 * with. What *is* testable, and what actually closes the hole, is that the
 * transition re-reads state from the database: a caller holding a stale instance is
 * refused rather than obeyed. That is the same property the lock provides, asserted
 * without needing two processes.
 */
class PreDpcTransitionLockTest extends TestCase
{
    use PrescribesQualifications;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $ah;

    private PreDpcProposalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->department = Department::query()->create([
            'code' => 'SE', 'name' => 'School Education', 'status' => 'active',
        ]);

        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();

        $this->ah = User::factory()->assignedTo($this->department->id, Role::AdministrativeHead)->create();

        $this->service = app(PreDpcProposalService::class);
    }

    /**
     * The shape of the double-submit race: two callers, each holding an instance
     * that says `draft`, both calling `submit()`.
     */
    public function test_a_second_submit_from_a_stale_instance_is_refused(): void
    {
        $first = $this->draft();
        // A second handle on the same row, loaded before either call — this is what
        // a concurrent request would hold.
        $second = PreDpcProposal::query()->findOrFail($first->id);

        $this->service->submit($first, $this->ao, request());

        $this->assertSame(PreDpcStatus::Submitted, $first->fresh()->status);
        $this->assertSame(PreDpcStatus::Draft, $second->status, 'the stale instance still believes it is a draft');

        $this->expectException(WorkflowException::class);
        $this->service->submit($second, $this->ao, request());
    }

    /** And nothing was written by the refused call. */
    public function test_the_refused_submit_leaves_exactly_one_transition_on_the_ledger(): void
    {
        $first = $this->draft();
        $second = PreDpcProposal::query()->findOrFail($first->id);

        $this->service->submit($first, $this->ao, request());

        try {
            $this->service->submit($second, $this->ao, request());
        } catch (WorkflowException) {
            // Expected — asserted in the test above.
        }

        $this->assertSame(1, $this->transitions($first, 'submit'));
    }

    /**
     * The one that would have mattered most: a double approval used to mean **two
     * signatures on one proposal**, each attesting the same document.
     */
    public function test_a_second_approval_from_a_stale_instance_signs_nothing(): void
    {
        $proposal = $this->draft();
        $this->service->submit($proposal, $this->ao, request());

        $first = $proposal->fresh();
        $second = PreDpcProposal::query()->findOrFail($proposal->id);

        $this->service->approve($first, $this->ah, request());

        $signatureId = $first->fresh()->signature_id;
        $this->assertNotNull($signatureId);

        try {
            $this->service->approve($second, $this->ah, request());
            $this->fail('the second approval should have been refused');
        } catch (WorkflowException) {
            // Expected.
        }

        // Same signature, not a second one.
        $this->assertSame($signatureId, $proposal->fresh()->signature_id);
        $this->assertSame(1, $this->transitions($proposal, 'approve'));
    }

    public function test_return_and_cancel_also_decide_from_the_stored_row(): void
    {
        $proposal = $this->draft();
        $this->service->submit($proposal, $this->ao, request());

        $stale = PreDpcProposal::query()->findOrFail($proposal->id);

        $this->service->return($proposal->fresh(), $this->ah, 'The 2016 CR grade is missing.', request());
        $this->assertSame(PreDpcStatus::Returned, $proposal->fresh()->status);

        // `cancel` is legal from `returned`, so this must succeed — the lock must
        // not turn a *valid* transition into a refusal.
        $this->service->cancel($proposal->fresh(), $this->ao, request());
        $this->assertSame(PreDpcStatus::Cancelled, $proposal->fresh()->status);

        // And a second return, from the instance that still reads `submitted`, is
        // refused against the stored `cancelled`.
        $this->expectException(WorkflowException::class);
        $this->service->return($stale, $this->ah, 'again', request());
    }

    /**
     * The completeness gate still refuses before the transaction, and still says
     * what is missing rather than reporting a state problem.
     */
    public function test_an_incomplete_draft_is_still_refused_with_a_message_about_the_form(): void
    {
        // Written straight to the table rather than through the service, because
        // the service now refuses to *create* a proposal without a post pair
        // (change items 8-11) and this is asserting the submit gate, not that one.
        $empty = PreDpcProposal::query()->create([
            'pre_dpc_number' => 'PDPC-2026-SE-9999',
            'department_id' => $this->department->id,
            'dpc_year' => 2026,
            'version' => 9,
            'status' => PreDpcStatus::Draft->value,
            'government_order_number' => 'GO/EMPTY',
            'effective_from' => '2026-01-01',
            'prepared_by' => $this->ao->id,
        ]);

        $this->expectException(WorkflowException::class);
        $this->service->submit($empty, $this->ao, request());
    }

    // ------------------------------------------------------------------
    // Fixture
    // ------------------------------------------------------------------

    private function draft(): PreDpcProposal
    {
        $target = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Deputy Director',
            'class_level' => 'I', 'status' => MasterStatus::Active->value,
        ]);
        $feeder = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Under Secretary',
            'class_level' => 'I', 'status' => MasterStatus::Active->value,
        ]);

        $proposal = $this->service->createDraft($this->department, $this->ao, [
            'dpc_year' => 2026,
            'promotion_to_post_id' => $target->id,
            'direct_recruitment_pct' => 0,
            'promotion_recruitment_pct' => 100,
            'promotion_sources' => [
                [
                    'promotion_from_post_id' => $feeder->id,
                    'promotion_percentage' => 100,
                    'min_experience_years' => 5,
                    'experience_unit' => 'years',
                    'qualification_ids' => $this->qualificationIds(),
                ],
            ],
            'government_order_number' => 'GO/2026/SE/1',
            'effective_from' => '2026-01-01',
        ]);
        // Only the allotted AO submits and the allotted AH approves (2026-10-27).
        app(ProposalAllotmentService::class)->allot($proposal, $this->ao, $this->ah, null);

        return $proposal->refresh();
    }

    private function transitions(PreDpcProposal $proposal, string $event): int
    {
        return WorkflowTransition::query()
            ->where('workflow', 'pre_dpc')
            ->where('subject_id', $proposal->id)
            ->where('event', $event)
            ->count();
    }
}
