<?php

declare(strict_types=1);

namespace Tests\Feature\Circulation;

use App\Domain\Authentication\Models\User;
use App\Domain\Circulation\Enums\CirculationStatus;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Circulation\Services\CirculationService;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use App\Domain\Review\Models\DpcReviewLink;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Circulation\Concerns\BuildsCirculations;
use Tests\TestCase;

/**
 * Raising a circulation note (PPT S45, S47) — M9 Phase 1.
 *
 * The milestone's central guarantee is tested first and stated plainly:
 * **a circulation changes nothing about the DPC it circulates.** Everything
 * else — the origin, the items, the admissibility rules — is bookkeeping
 * around that.
 */
class CirculationRaiseTest extends TestCase
{
    use BuildsCirculations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCirculation();
    }

    private function service(): CirculationService
    {
        return app(CirculationService::class);
    }

    public function test_a_circulation_changes_nothing_about_the_origin_dpc(): void
    {
        $run = DpcCalculationRun::query()->where('dpc_proposal_id', $this->proposal->id)->latest('id')->firstOrFail();

        $before = [
            'proposal' => $this->proposal->fresh()->toArray(),
            'proceeding' => $this->proceeding->fresh()->toArray(),
            'run' => $run->fresh()->toArray(),
            'results' => DpcCalculationResult::query()
                ->where('dpc_calculation_run_id', $run->id)
                ->orderBy('id')->get()->toArray(),
            'rows' => $this->proposal->evaluationRows()->orderBy('sn')->get()->toArray(),
        ];

        $this->service()->raise(
            $this->proposal->fresh(),
            $this->authorizedOfficer,
            Carbon::parse('2026-07-25'),
            $this->itemsFor($this->propertyHold()),
        );

        $after = [
            'proposal' => $this->proposal->fresh()->toArray(),
            'proceeding' => $this->proceeding->fresh()->toArray(),
            'run' => $run->fresh()->toArray(),
            'results' => DpcCalculationResult::query()
                ->where('dpc_calculation_run_id', $run->id)
                ->orderBy('id')->get()->toArray(),
            'rows' => $this->proposal->evaluationRows()->orderBy('sn')->get()->toArray(),
        ];

        $this->assertSame($before, $after, 'A circulation must not alter the DPC it circulates.');
    }

    public function test_no_calculation_run_is_created_by_raising_a_circulation(): void
    {
        $runsBefore = DpcCalculationRun::query()->count();

        $this->service()->raise(
            $this->proposal->fresh(),
            $this->authorizedOfficer,
            Carbon::parse('2026-07-25'),
            $this->itemsFor($this->propertyHold()),
        );

        $this->assertSame($runsBefore, DpcCalculationRun::query()->count());
    }

    public function test_raising_creates_no_dpc_proposal_row(): void
    {
        $before = DpcProposal::query()->count();

        $this->service()->raise(
            $this->proposal->fresh(),
            $this->authorizedOfficer,
            Carbon::parse('2026-07-25'),
            $this->itemsFor($this->propertyHold()),
        );

        // AD-24: a Circulation is its own aggregate and never becomes a
        // proposal of type `circulation`.
        $this->assertSame($before, DpcProposal::query()->count());
    }

    public function test_it_records_the_origin_the_items_and_the_evidence(): void
    {
        $hold = $this->propertyHold();

        $circulation = $this->service()->raise(
            $this->proposal->fresh(),
            $this->authorizedOfficer,
            Carbon::parse('2026-07-25'),
            $this->itemsFor($hold),
        );

        $this->assertSame(CirculationStatus::Draft, $circulation->status);
        $this->assertSame($this->proposal->id, $circulation->origin_dpc_proposal_id);
        $this->assertSame($this->proceeding->id, $circulation->origin_dpc_proceeding_id);
        $this->assertSame('DPC-2026-HE-0001', $circulation->origin_dpc_code);
        $this->assertNull($circulation->circulation_code, 'The code is minted at approval, not at raising.');

        $item = $circulation->items()->firstOrFail();
        $this->assertSame($hold->id, $item->dpc_proceeding_hold_id);
        $this->assertSame(8, $item->sn);
        $this->assertSame('US8', $item->officer_name);
        $this->assertSame('Record supplied by the department', $item->resolution_detail);
        $this->assertSame('HE/DPC/2026/17', $item->resolution_reference);
    }

    public function test_raising_does_not_release_the_hold(): void
    {
        $hold = $this->propertyHold();

        $this->service()->raise(
            $this->proposal->fresh(),
            $this->authorizedOfficer,
            Carbon::parse('2026-07-25'),
            $this->itemsFor($hold),
        );

        // AQ-17: the hold is released at signature, and not before. A note
        // that is raised and never signed must leave the promotion reserved.
        $this->assertNull($hold->fresh()->released_at);
        $this->assertNull(DpcCirculation::query()->firstOrFail()->items()->firstOrFail()->hold_released_at);
    }

    public function test_it_records_a_timeline_event_on_the_origin_dpc(): void
    {
        $this->service()->raise(
            $this->proposal->fresh(),
            $this->authorizedOfficer,
            Carbon::parse('2026-07-25'),
            $this->itemsFor($this->propertyHold()),
        );

        $entry = ActivityTimeline::query()->where('event', 'dpc_circulation.raised')->firstOrFail();

        // Recorded on the origin proposal as subject, as M6/M7/M8 all do, so
        // one query still answers "everything that happened to this DPC".
        $this->assertSame($this->proposal->id, $entry->subject_id);
        $this->assertSame('Authorized Officer', $entry->actor_name);
        $this->assertSame('DPC-2026-HE-0001', $entry->properties['origin_dpc_code']);
        $this->assertCount(1, $entry->properties['items']);
    }

    public function test_a_dpc_of_another_department_cannot_be_circulated(): void
    {
        $other = Department::query()->create(['code' => 'FI', 'name' => 'Finance', 'status' => 'active']);
        $intruder = User::factory()->assignedTo($other->id, Role::AuthorizedOfficer)->create();

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('belongs to another department');

        $this->service()->raise(
            $this->proposal->fresh(),
            $intruder,
            Carbon::parse('2026-07-25'),
            $this->itemsFor($this->propertyHold()),
        );
    }

    public function test_a_superseded_dpc_cannot_be_circulated(): void
    {
        // AQ-18. A promotion must not be confirmed under a list a Review has
        // replaced — the case M8's SUPERSEDED watermark exists for.
        DpcReviewLink::query()->create([
            'review_dpc_proposal_id' => $this->proposal->id,
            'original_dpc_proposal_id' => $this->proposal->id,
            'chain_uuid' => (string) Str::uuid(),
            'review_number' => 1,
            'chain_depth' => 2,
            'effect' => 'supersedes',
            'superseded_at' => now(),
            'established_by_name' => 'Tester',
            'established_at' => now(),
        ]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('superseded by a Review');

        $this->service()->raise(
            $this->proposal->fresh(),
            $this->authorizedOfficer,
            Carbon::parse('2026-07-25'),
            $this->itemsFor($this->propertyHold()),
        );
    }

    public function test_a_hold_from_another_dpc_is_refused(): void
    {
        $foreign = DpcProceedingHold::query()->create([
            'dpc_proceeding_id' => $this->proceeding->id,
            'sn' => 99,
            'officer_name' => 'Ghost',
            'reason_code' => 'property_missing',
            'is_automatic' => true,
            'recorded_by_name' => 'Tester',
            'recorded_at' => now(),
            'released_at' => now(),
        ]);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('already been released');

        $this->service()->raise(
            $this->proposal->fresh(),
            $this->authorizedOfficer,
            Carbon::parse('2026-07-25'),
            $this->itemsFor($foreign),
        );
    }

    public function test_the_same_hold_cannot_be_covered_by_two_open_circulations(): void
    {
        $hold = $this->propertyHold();

        $this->service()->raise(
            $this->proposal->fresh(), $this->authorizedOfficer,
            Carbon::parse('2026-07-25'), $this->itemsFor($hold),
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('already covered by an open circulation');

        $this->service()->raise(
            $this->proposal->fresh(), $this->authorizedOfficer,
            Carbon::parse('2026-07-26'), $this->itemsFor($hold),
        );
    }

    public function test_an_empty_selection_is_refused(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('at least one held officer');

        $this->service()->raise(
            $this->proposal->fresh(), $this->authorizedOfficer, Carbon::parse('2026-07-25'), [],
        );
    }

    public function test_eligible_origins_lists_only_concluded_dpcs_with_holds_in_force(): void
    {
        $origins = $this->service()->eligibleOrigins($this->department->id);

        $this->assertCount(1, $origins);
        $this->assertSame($this->proposal->id, $origins->first()->id);
    }

    public function test_a_dpc_whose_holds_are_all_released_is_no_longer_an_eligible_origin(): void
    {
        DpcProceedingHold::query()
            ->where('dpc_proceeding_id', $this->proceeding->id)
            ->update(['released_at' => now()]);

        $this->assertCount(0, $this->service()->eligibleOrigins($this->department->id));
    }

    public function test_outstanding_holds_are_listed_for_the_origin(): void
    {
        $holds = $this->service()->outstandingHolds($this->proposal->fresh());

        $this->assertCount(2, $holds);
        $this->assertEqualsCanonicalizing([8, 9], $holds->pluck('sn')->all());
    }
}
