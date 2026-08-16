<?php

declare(strict_types=1);

namespace Tests\Feature\Circulation;

use App\Domain\Circulation\Enums\CirculationDecision;
use App\Domain\Circulation\Enums\CirculationStatus;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Circulation\Models\DpcCirculationItem;
use App\Domain\Circulation\Services\CirculationCommissionService;
use App\Domain\Circulation\Services\CirculationService;
use App\Domain\Circulation\Services\CirculationWorkflowService;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Support\Carbon;
use Tests\Feature\Circulation\Concerns\BuildsCirculations;
use Tests\TestCase;

/**
 * The Commission's consent workflow (PPT S46, S48) — M9 Phase 2.
 *
 * Consent is **recorded intent**; the signature is the operative act. These
 * tests pin the separation, because collapsing the two is the easy mistake and
 * it would release a promotion on a note nobody signed.
 */
class CirculationConsentTest extends TestCase
{
    use BuildsCirculations;

    private DpcCirculation $circulation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCirculation();

        $raised = app(CirculationService::class)->raise(
            $this->proposal->fresh(),
            $this->authorizedOfficer,
            Carbon::parse('2026-07-25'),
            $this->itemsFor($this->propertyHold()),
        );

        $workflow = app(CirculationWorkflowService::class);
        $this->circulation = $workflow->approve(
            $workflow->submit($raised, $this->authorizedOfficer, request()),
            $this->administrativeHead,
            request(),
        );
    }

    private function commission(): CirculationCommissionService
    {
        return app(CirculationCommissionService::class);
    }

    private function item(): DpcCirculationItem
    {
        return $this->circulation->items()->firstOrFail();
    }

    public function test_the_commission_records_a_decision_per_officer(): void
    {
        $decided = $this->commission()->decide(
            $this->circulation, $this->item(), $this->memberOne, CirculationDecision::Consented,
        );

        $this->assertSame(CirculationDecision::Consented, $decided->decision);
        $this->assertSame('Member One', $decided->decided_by_name);
        $this->assertNotNull($decided->decided_at);
    }

    public function test_a_decision_writes_its_own_timeline_event(): void
    {
        $this->commission()->decide(
            $this->circulation, $this->item(), $this->memberOne, CirculationDecision::Consented,
        );

        $entry = ActivityTimeline::query()->where('event', 'dpc_circulation.item_decided')->firstOrFail();

        $this->assertSame($this->proposal->id, $entry->subject_id);
        $this->assertSame('US8', $entry->properties['officer_name']);
        $this->assertSame('consented', $entry->properties['decision']);
    }

    public function test_a_decision_alone_releases_nothing(): void
    {
        $hold = $this->propertyHold();

        $this->commission()->decide(
            $this->circulation, $this->item(), $this->memberOne, CirculationDecision::Consented,
        );

        // AQ-17. Recorded intent is not the operative act.
        $this->assertNull($hold->fresh()->released_at);
        $this->assertNull($this->item()->fresh()->hold_released_at);
    }

    public function test_the_note_cannot_be_consented_until_every_officer_is_decided(): void
    {
        // Two officers on the note, one decided.
        $second = app(CirculationService::class);
        $this->circulation->items()->create([
            'dpc_proceeding_hold_id' => $this->crHold()->id,
            'sn' => 9,
            'officer_name' => 'US9',
            'reason_code' => 'cr_years_missing',
            'resolution_detail' => 'Added for this test',
            'resolution_received_on' => '2026-07-20',
        ]);

        $this->commission()->decide(
            $this->circulation->fresh(), $this->item(), $this->memberOne, CirculationDecision::Consented,
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('must be decided');

        $this->commission()->consent($this->circulation->fresh(), $this->memberOne, request());
        unset($second);
    }

    public function test_consent_moves_the_note_and_records_the_actor(): void
    {
        $this->commission()->decide(
            $this->circulation, $this->item(), $this->memberOne, CirculationDecision::Consented,
        );

        $consented = $this->commission()->consent($this->circulation->fresh(), $this->memberOne, request());

        $this->assertSame(CirculationStatus::Consented, $consented->status);
        $this->assertSame('Member One', $consented->consented_by_name);
        $this->assertNotNull($consented->consented_at);
    }

    public function test_only_the_member_who_conducted_the_original_dpc_may_act(): void
    {
        // AQ-20, reusing AQ-6: holding the Member role is not the same as
        // being *this* DPC's member.
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('who conducted the original DPC');

        $this->commission()->decide(
            $this->circulation, $this->item(), $this->memberTwo, CirculationDecision::Consented,
        );
    }

    public function test_the_chairman_is_not_exempt(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('who conducted the original DPC');

        $this->commission()->decide(
            $this->circulation, $this->item(), $this->chairman, CirculationDecision::Consented,
        );
    }

    public function test_a_note_still_with_the_department_cannot_be_decided(): void
    {
        $draft = app(CirculationService::class)->raise(
            $this->proposal->fresh(),
            $this->authorizedOfficer,
            Carbon::parse('2026-07-26'),
            $this->itemsFor($this->crHoldAsPropertyProxy()),
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('not currently with the Commission');

        $this->commission()->decide(
            $draft, $draft->items()->firstOrFail(), $this->memberOne, CirculationDecision::Consented,
        );
    }

    public function test_the_commission_can_return_the_note_to_the_department(): void
    {
        $returned = $this->commission()->returnToDepartment(
            $this->circulation, $this->memberOne, 'Attach the property receipt.', request(),
        );

        $this->assertSame(CirculationStatus::Returned, $returned->status);
        $this->assertSame('Attach the property receipt.', $returned->return_remarks);

        // The hold stands. Returning is not refusing.
        $this->assertNull($this->propertyHold()->fresh()->released_at);
    }

    public function test_a_returned_note_can_be_resubmitted_by_the_department(): void
    {
        $this->commission()->returnToDepartment(
            $this->circulation, $this->memberOne, 'More evidence please.', request(),
        );

        $resubmitted = app(CirculationWorkflowService::class)
            ->submit($this->circulation->fresh(), $this->authorizedOfficer, request());

        $this->assertSame(CirculationStatus::Submitted, $resubmitted->status);
    }

    public function test_there_is_no_refusal_decision(): void
    {
        // BD-12 is raised, not answered. S45 describes no power to refuse a
        // promotion on the strength of a circulation, so none was invented.
        $this->assertEqualsCanonicalizing(
            ['consented', 'returned'],
            array_map(static fn (CirculationDecision $d): string => $d->value, CirculationDecision::cases()),
        );
    }

    /**
     * A second hold to put on a second note — the CR one is refused at
     * raising, so this test needs a hold the department may actually use.
     */
    private function crHoldAsPropertyProxy(): DpcProceedingHold
    {
        return DpcProceedingHold::query()->create([
            'dpc_proceeding_id' => $this->proceeding->id,
            'sn' => 12,
            'officer_name' => 'US12',
            'reason_code' => 'property_missing',
            'detail' => 'Not recorded for 2015',
            'is_automatic' => false,
            'recorded_by_name' => 'Member One',
            'recorded_at' => now(),
        ]);
    }
}
