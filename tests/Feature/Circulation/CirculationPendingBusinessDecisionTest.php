<?php

declare(strict_types=1);

namespace Tests\Feature\Circulation;

use App\Domain\Circulation\Exceptions\PendingBusinessDecisionException;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Circulation\Models\DpcCirculationItem;
use App\Domain\Circulation\Services\CirculationService;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Review\Models\DpcReviewLink;
use App\Domain\Shared\Models\ActivityTimeline;
use Illuminate\Support\Carbon;
use Tests\Feature\Circulation\Concerns\BuildsCirculations;
use Tests\TestCase;

/**
 * BD-15 — a CR-ground hold is detected, refused in a structured way, and
 * recorded. **Nothing is decided.** M9 Phase 1.
 *
 * The instruction this implements is unusually precise, and each clause has a
 * test below: *detect the condition; return a structured "Pending Business
 * Decision (BD-15)" response; record an audit event; do not calculate,
 * re-score or escalate automatically.*
 *
 * Why the ground is blocked at all: the frozen engine scores *"the five most
 * recent **available** CR years"*, so a CR-held officer's window is incomplete
 * by definition. Supplying the missing grade grows the window, makes a Super
 * Seat reachable for the first time — which grants notional seniority and
 * **reorders the main list for officers who were never notified** — and can
 * trip either hard gate. Whether S45's CR ground may be routed to a Review
 * instead is CGPSC's to answer, not ours (AD-2).
 */
class CirculationPendingBusinessDecisionTest extends TestCase
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

    public function test_a_cr_ground_hold_is_detected_without_scoring_anything(): void
    {
        $pending = $this->service()->assessGrounds(
            $this->service()->outstandingHolds($this->proposal->fresh())
        );

        $this->assertNotNull($pending);
        $this->assertSame('BD-15', $pending->code);

        // The detection reads `reason_code` — it is admissibility, not
        // outcome, which is exactly why it can live here at all: deciding
        // whether the mark *would* change is a scoring operation, and AD-23
        // forbids one.
        $this->assertCount(1, $pending->affected);
        $this->assertSame(9, $pending->affected[0]['sn']);
        $this->assertSame('cr_years_missing', $pending->affected[0]['reason_code']);
    }

    public function test_a_property_ground_hold_alone_raises_no_pending_decision(): void
    {
        $holds = $this->service()->outstandingHolds($this->proposal->fresh())
            ->filter(fn ($hold): bool => $hold->reason_code->value === 'property_missing');

        $this->assertNull($this->service()->assessGrounds($holds));
    }

    public function test_raising_with_a_cr_ground_throws_a_structured_pending_decision(): void
    {
        try {
            $this->service()->raise(
                $this->proposal->fresh(),
                $this->authorizedOfficer,
                Carbon::parse('2026-07-25'),
                $this->itemsFor($this->crHold()),
            );
            $this->fail('A CR-ground circulation must not be accepted while BD-15 is open.');
        } catch (PendingBusinessDecisionException $e) {
            $payload = $e->decision->toArray();

            $this->assertSame('pending_business_decision', $payload['status']);
            $this->assertSame('BD-15', $payload['code']);
            $this->assertNotEmpty($payload['question']);
            $this->assertSame('docs/PENDING_BUSINESS_DECISIONS.md', $payload['route']);
            $this->assertNotEmpty($payload['not_done']);
            $this->assertSame(9, $payload['affected'][0]['sn']);
        }
    }

    public function test_the_refusal_states_what_was_deliberately_not_done(): void
    {
        $pending = $this->service()->assessGrounds(
            $this->service()->outstandingHolds($this->proposal->fresh())
        );

        $notDone = implode(' ', $pending->notDone);

        // The part a reader cannot otherwise verify, so it is stated.
        $this->assertStringContainsString('No calculation', $notDone);
        $this->assertStringContainsString('not escalated', $notDone);
        $this->assertStringContainsString('No hold was released', $notDone);
    }

    public function test_a_mixed_selection_is_refused_whole_rather_than_split(): void
    {
        // Splitting would mean the system deciding which officers a note
        // covers, which is the department's decision — and it would leave a
        // half-raised circulation nobody asked for.
        $this->expectException(PendingBusinessDecisionException::class);

        $this->service()->raise(
            $this->proposal->fresh(),
            $this->authorizedOfficer,
            Carbon::parse('2026-07-25'),
            $this->itemsFor($this->propertyHold(), $this->crHold()),
        );
    }

    public function test_nothing_is_created_when_the_decision_is_pending(): void
    {
        try {
            $this->service()->raise(
                $this->proposal->fresh(),
                $this->authorizedOfficer,
                Carbon::parse('2026-07-25'),
                $this->itemsFor($this->crHold()),
            );
        } catch (PendingBusinessDecisionException) {
            // expected
        }

        $this->assertSame(0, DpcCirculation::query()->count());
        $this->assertSame(0, DpcCirculationItem::query()->count());
    }

    public function test_no_calculation_is_performed_and_no_hold_is_released(): void
    {
        $runsBefore = DpcCalculationRun::query()->count();
        $hold = $this->crHold();

        try {
            $this->service()->raise(
                $this->proposal->fresh(),
                $this->authorizedOfficer,
                Carbon::parse('2026-07-25'),
                $this->itemsFor($hold),
            );
        } catch (PendingBusinessDecisionException) {
            // expected
        }

        $this->assertSame($runsBefore, DpcCalculationRun::query()->count());
        $this->assertNull($hold->fresh()->released_at);
    }

    public function test_no_review_is_created_automatically(): void
    {
        $proposalsBefore = DpcProposal::query()->count();

        try {
            $this->service()->raise(
                $this->proposal->fresh(),
                $this->authorizedOfficer,
                Carbon::parse('2026-07-25'),
                $this->itemsFor($this->crHold()),
            );
        } catch (PendingBusinessDecisionException) {
            // expected
        }

        // "Do not escalate automatically." Routing the case to a Review is
        // itself the unruled question; doing it silently would answer BD-15
        // by acting.
        $this->assertSame($proposalsBefore, DpcProposal::query()->count());
        $this->assertSame(0, DpcReviewLink::query()->count());
    }

    public function test_the_refusal_is_recorded_on_the_timeline(): void
    {
        try {
            $this->service()->raise(
                $this->proposal->fresh(),
                $this->authorizedOfficer,
                Carbon::parse('2026-07-25'),
                $this->itemsFor($this->crHold()),
            );
        } catch (PendingBusinessDecisionException) {
            // expected
        }

        $entry = ActivityTimeline::query()
            ->where('event', 'dpc_circulation.pending_business_decision')
            ->firstOrFail();

        // Written even though nothing else was: without it, a case blocked on
        // CGPSC is indistinguishable from one nobody ever raised — and BD-13
        // (how long a promotion may stay held) cannot be answered from an
        // empty record.
        $this->assertSame($this->proposal->id, $entry->subject_id);
        $this->assertSame('Authorized Officer', $entry->actor_name);
        $this->assertSame('BD-15', $entry->properties['code']);
        $this->assertSame(9, $entry->properties['affected'][0]['sn']);
    }

    public function test_the_screen_receives_the_structured_decision_rather_than_an_error(): void
    {
        $response = $this->actingAs($this->authorizedOfficer)
            ->post(route('dpc-circulations.store'), [
                'origin_dpc_proposal_uuid' => $this->proposal->uuid,
                'proposal_date' => '2026-07-25',
                'items' => $this->itemsFor($this->crHold()),
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('pendingBusinessDecision');
        // Not a validation failure: the department did nothing wrong.
        $response->assertSessionHasNoErrors();

        $payload = session('pendingBusinessDecision');
        $this->assertSame('BD-15', $payload['code']);
    }

    public function test_an_api_caller_receives_a_structured_409(): void
    {
        $response = $this->actingAs($this->authorizedOfficer)
            ->postJson(route('dpc-circulations.store'), [
                'origin_dpc_proposal_uuid' => $this->proposal->uuid,
                'proposal_date' => '2026-07-25',
                'items' => $this->itemsFor($this->crHold()),
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('status', 'pending_business_decision');
        $response->assertJsonPath('code', 'BD-15');
        $response->assertJsonPath('affected.0.sn', 9);
    }
}
