<?php

declare(strict_types=1);

namespace Tests\Feature\Circulation;

use App\Domain\Circulation\Enums\CirculationStatus;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Circulation\Services\CirculationService;
use App\Domain\Circulation\Services\CirculationWorkflowService;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Shared\Workflow\WorkflowTransition;
use Illuminate\Support\Carbon;
use Tests\Feature\Circulation\Concerns\BuildsCirculations;
use Tests\TestCase;

/**
 * The department's side of a circulation note (PPT S45) — M9 Phase 1.
 *
 * Phase 1 stops at the department's approval. `consent` and `sign` are the
 * Commission's, and they arrive with Phase 2 — so the tests here also assert
 * that those transitions **do not exist yet**, because a transition nothing can
 * perform reads as supported behaviour when it is not.
 */
class CirculationWorkflowTest extends TestCase
{
    use BuildsCirculations;

    private DpcCirculation $circulation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCirculation();

        $this->circulation = app(CirculationService::class)->raise(
            $this->proposal->fresh(),
            $this->authorizedOfficer,
            Carbon::parse('2026-07-25'),
            $this->itemsFor($this->propertyHold()),
        );
    }

    private function workflow(): CirculationWorkflowService
    {
        return app(CirculationWorkflowService::class);
    }

    public function test_the_officer_submits_and_the_head_approves_and_signs(): void
    {
        $submitted = $this->workflow()->submit($this->circulation, $this->authorizedOfficer, request());
        $this->assertSame(CirculationStatus::Submitted, $submitted->status);

        $approved = $this->workflow()->approve($submitted, $this->administrativeHead, request());

        $this->assertSame(CirculationStatus::Approved, $approved->status);
        $this->assertSame('Administrative Head', $approved->approved_by_name);
        $this->assertNotNull($approved->approved_at);

        // AQ-23 — the AH's signature is what forwards it to the Commission.
        $this->assertNotNull($approved->approval_signature_id);
    }

    public function test_the_circulation_code_is_minted_at_approval_and_is_not_a_dpc_id(): void
    {
        $approved = $this->workflow()->approve(
            $this->workflow()->submit($this->circulation, $this->authorizedOfficer, request()),
            $this->administrativeHead,
            request(),
        );

        // AD-24: a Circulation is not a DPC and must never appear in any
        // record to have been one. The code names the DPC it belongs to and
        // says on its face what it is.
        $this->assertSame('HE202600001/C1', $approved->circulation_code);
        $this->assertStringContainsString('/C', $approved->circulation_code);
    }

    public function test_approval_does_not_release_the_hold(): void
    {
        $hold = $this->propertyHold();

        $this->workflow()->approve(
            $this->workflow()->submit($this->circulation, $this->authorizedOfficer, request()),
            $this->administrativeHead,
            request(),
        );

        // AQ-17. The department's approval forwards the note; only the
        // Commission's signature releases a promotion, and that is Phase 2.
        $this->assertNull($hold->fresh()->released_at);
    }

    public function test_the_head_can_return_it_and_the_officer_can_resubmit(): void
    {
        $submitted = $this->workflow()->submit($this->circulation, $this->authorizedOfficer, request());

        $returned = $this->workflow()->return($submitted, $this->administrativeHead, 'Attach the receipt.', request());
        $this->assertSame(CirculationStatus::Returned, $returned->status);
        $this->assertSame('Attach the receipt.', $returned->return_remarks);

        $resubmitted = $this->workflow()->submit($returned, $this->authorizedOfficer, request());
        $this->assertSame(CirculationStatus::Submitted, $resubmitted->status);
    }

    public function test_a_draft_can_be_cancelled(): void
    {
        $cancelled = $this->workflow()->cancel($this->circulation, $this->authorizedOfficer, request());

        $this->assertSame(CirculationStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
    }

    public function test_a_cancelled_circulation_frees_its_hold_for_a_new_one(): void
    {
        $this->workflow()->cancel($this->circulation, $this->authorizedOfficer, request());

        $second = app(CirculationService::class)->raise(
            $this->proposal->fresh(),
            $this->authorizedOfficer,
            Carbon::parse('2026-07-26'),
            $this->itemsFor($this->propertyHold()),
        );

        $this->assertSame(CirculationStatus::Draft, $second->status);
    }

    public function test_the_authorized_officer_cannot_approve_their_own_circulation(): void
    {
        $submitted = $this->workflow()->submit($this->circulation, $this->authorizedOfficer, request());

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('not permitted');

        $this->workflow()->approve($submitted, $this->authorizedOfficer, request());
    }

    public function test_a_draft_cannot_be_approved_without_being_submitted(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('not allowed from state [draft]');

        $this->workflow()->approve($this->circulation, $this->administrativeHead, request());
    }

    public function test_an_approved_circulation_cannot_be_cancelled(): void
    {
        $approved = $this->workflow()->approve(
            $this->workflow()->submit($this->circulation, $this->authorizedOfficer, request()),
            $this->administrativeHead,
            request(),
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('not allowed from state [approved]');

        $this->workflow()->cancel($approved, $this->authorizedOfficer, request());
    }

    public function test_a_circulation_with_no_items_cannot_be_submitted(): void
    {
        $this->circulation->items()->delete();

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('covers no officers');

        $this->workflow()->submit($this->circulation->fresh(), $this->authorizedOfficer, request());
    }

    public function test_every_transition_is_written_to_the_workflow_ledger(): void
    {
        $this->workflow()->approve(
            $this->workflow()->submit($this->circulation, $this->authorizedOfficer, request()),
            $this->administrativeHead,
            request(),
        );

        $transitions = WorkflowTransition::query()
            ->where('workflow', 'dpc_circulation')
            ->orderBy('id')
            ->get();

        $this->assertSame(['submit', 'approve'], $transitions->pluck('event')->all());
        $this->assertSame(['draft', 'submitted'], $transitions->pluck('from_state')->all());
        $this->assertSame(['submitted', 'approved'], $transitions->pluck('to_state')->all());
        $this->assertNotNull($transitions->last()->signature_id);
    }

    public function test_the_workflow_declares_exactly_what_phases_1_and_2_perform(): void
    {
        $declared = array_keys(config('dpc.workflows.dpc_circulation.transitions'));

        // This assertion read `['submit', 'approve', 'return', 'cancel']` in
        // Phase 1 and failed the moment Phase 2 declared the Commission's
        // three — which is the guard doing its job, not a regression. The
        // standing rule since M6 is that a phase declares only what it
        // performs, and Phase 2 performs these.
        $this->assertEqualsCanonicalizing(
            ['submit', 'approve', 'return', 'cancel', 'consent', 'return_to_department', 'sign'],
            $declared,
        );
    }

    public function test_the_screens_are_reachable_and_department_scoped(): void
    {
        $this->actingAs($this->authorizedOfficer)
            ->get(route('dpc-circulations.index'))
            ->assertOk();

        $this->actingAs($this->authorizedOfficer)
            ->get(route('dpc-circulations.create'))
            ->assertOk()
            ->assertSee('HE202600001');

        $this->actingAs($this->authorizedOfficer)
            ->get(route('dpc-circulations.show', $this->circulation))
            ->assertOk()
            ->assertSee('US8');
    }

    public function test_a_hold_can_never_be_deleted_by_removing_a_circulation(): void
    {
        // `restrictOnDelete`: a hold is the reason a promotion did not take
        // effect, and no circulation may take one with it.
        $this->assertSame(2, DpcProceedingHold::query()->count());

        $this->circulation->items()->delete();
        $this->circulation->delete();

        $this->assertSame(2, DpcProceedingHold::query()->count());
    }
}
