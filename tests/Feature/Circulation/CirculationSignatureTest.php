<?php

declare(strict_types=1);

namespace Tests\Feature\Circulation;

use App\Domain\Circulation\Enums\CirculationDecision;
use App\Domain\Circulation\Enums\CirculationStatus;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Circulation\Services\CirculationCommissionService;
use App\Domain\Circulation\Services\CirculationResultService;
use App\Domain\Circulation\Services\CirculationService;
use App\Domain\Circulation\Services\CirculationWorkflowService;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Shared\Workflow\WorkflowTransition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Circulation\Concerns\BuildsCirculations;
use Tests\TestCase;

/**
 * Signature, hold release, and the guarantee that nothing else moved —
 * M9 Phase 2.
 *
 * **The signature is the operative act (AQ-17).** Everything before it is
 * recorded intent. These tests pin that, and pin the thing AD-23 exists to
 * protect: releasing a promotion changes **no officer's marks and no other
 * officer's position**, because nothing is recalculated.
 */
class CirculationSignatureTest extends TestCase
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

    private function consentAll(): DpcCirculation
    {
        foreach ($this->circulation->items as $item) {
            $this->commission()->decide($this->circulation, $item, $this->memberOne, CirculationDecision::Consented);
        }

        return $this->commission()->consent($this->circulation->fresh(), $this->memberOne, request());
    }

    public function test_signing_releases_the_consented_hold(): void
    {
        $hold = $this->propertyHold();
        $this->assertNull($hold->released_at);

        $signed = $this->commission()->sign($this->consentAll(), $this->memberOne, request());

        $this->assertSame(CirculationStatus::Signed, $signed->status);
        $this->assertNotNull($signed->signature_id);
        $this->assertNotNull($hold->fresh()->released_at, 'The hold must be released at signature.');
        $this->assertNotNull($signed->items()->firstOrFail()->hold_released_at);
    }

    public function test_a_returned_officer_is_not_released(): void
    {
        foreach ($this->circulation->items as $item) {
            $this->commission()->decide($this->circulation, $item, $this->memberOne, CirculationDecision::Returned);
        }

        $signed = $this->commission()->sign(
            $this->commission()->consent($this->circulation->fresh(), $this->memberOne, request()),
            $this->memberOne,
            request(),
        );

        $this->assertSame(CirculationStatus::Signed, $signed->status);
        // The note is signed; the promotion stays reserved. Signing is not a
        // blanket release — it releases what was consented to and nothing else.
        $this->assertNull($this->propertyHold()->fresh()->released_at);
    }

    public function test_a_note_that_is_never_signed_leaves_the_promotion_reserved(): void
    {
        $this->consentAll();

        // The mirror of M8's supersession test: consented but unsigned changes
        // nothing about the promotion.
        $this->assertNull($this->propertyHold()->fresh()->released_at);
    }

    public function test_a_note_cannot_be_signed_before_it_is_consented(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('not allowed from state [approved]');

        $this->commission()->sign($this->circulation, $this->memberOne, request());
    }

    public function test_each_release_writes_its_own_timeline_event(): void
    {
        $this->commission()->sign($this->consentAll(), $this->memberOne, request());

        $entry = ActivityTimeline::query()->where('event', 'dpc_circulation.hold_resolved')->firstOrFail();

        // A hold is the reason a promotion did not take effect; its release is
        // the reason one did. Each must be answerable on its own.
        $this->assertSame($this->proposal->id, $entry->subject_id);
        $this->assertSame('US8', $entry->properties['officer_name']);
        $this->assertSame($this->propertyHold()->uuid, $entry->properties['hold_uuid']);
        $this->assertNotNull($entry->properties['released_at']);
    }

    public function test_the_signed_transition_names_the_officers_released(): void
    {
        $this->commission()->sign($this->consentAll(), $this->memberOne, request());

        $transition = WorkflowTransition::query()
            ->where('workflow', 'dpc_circulation')->where('event', 'sign')->firstOrFail();

        // Design §15: a department holding a list with `CIR` against two
        // officers needs to know which of them the note released. The remark
        // is what reaches the notification.
        $this->assertStringContainsString('US8', (string) $transition->remarks);
        $this->assertNotNull($transition->signature_id);
    }

    public function test_releasing_a_promotion_changes_no_marks_and_no_other_officer(): void
    {
        $run = DpcCalculationRun::query()->where('dpc_proposal_id', $this->proposal->id)->latest('id')->firstOrFail();

        $before = [
            'run' => $run->fresh()->toArray(),
            'results' => DpcCalculationResult::query()
                ->where('dpc_calculation_run_id', $run->id)->orderBy('id')->get()->toArray(),
            'rows' => $this->proposal->evaluationRows()->orderBy('sn')->get()->toArray(),
            'proceeding' => $this->proceeding->fresh()->toArray(),
        ];

        $this->commission()->sign($this->consentAll(), $this->memberOne, request());

        $after = [
            'run' => $run->fresh()->toArray(),
            'results' => DpcCalculationResult::query()
                ->where('dpc_calculation_run_id', $run->id)->orderBy('id')->get()->toArray(),
            'rows' => $this->proposal->evaluationRows()->orderBy('sn')->get()->toArray(),
            'proceeding' => $this->proceeding->fresh()->toArray(),
        ];

        // AD-23, behaviourally. The promotion is released; nothing is re-decided.
        $this->assertSame($before, $after);
    }

    public function test_no_calculation_run_is_created_by_signing(): void
    {
        $runsBefore = DpcCalculationRun::query()->count();

        $this->commission()->sign($this->consentAll(), $this->memberOne, request());

        $this->assertSame($runsBefore, DpcCalculationRun::query()->count());
    }

    public function test_the_other_officers_hold_is_untouched(): void
    {
        $crHold = $this->crHold();

        $this->commission()->sign($this->consentAll(), $this->memberOne, request());

        // US9 is not on this note. Signing must not touch them.
        $this->assertNull($crHold->fresh()->released_at);
        $this->assertSame(2, DpcProceedingHold::query()->count());
    }

    public function test_the_before_and_after_view_reads_the_stored_result(): void
    {
        $rows = app(CirculationResultService::class)->beforeAndAfter($this->circulation);

        $this->assertNotEmpty($rows);

        $us8 = $rows->firstWhere('sn', 8);
        $this->assertTrue($us8['held_before']);
        // Undecided: nothing would be released, so "after" still shows CIR.
        $this->assertTrue($us8['held_after']);
        $this->assertTrue($us8['on_this_note']);

        // US9 is held on the CR ground and is **not** on this note. Both
        // columns must be identical for them — which is the property S48 is
        // really asserting: a circulation changes the officers it covers and
        // nobody else.
        $us9 = $rows->firstWhere('sn', 9);
        $this->assertNotNull($us9, 'The other held officer must still appear in the stored result.');
        $this->assertTrue($us9['held_before']);
        $this->assertTrue($us9['held_after']);
        $this->assertFalse($us9['on_this_note']);
    }

    public function test_the_after_column_clears_cir_once_the_officer_is_consented(): void
    {
        foreach ($this->circulation->items as $item) {
            $this->commission()->decide($this->circulation, $item, $this->memberOne, CirculationDecision::Consented);
        }

        $rows = app(CirculationResultService::class)->beforeAndAfter($this->circulation->fresh());
        $us8 = $rows->firstWhere('sn', 8);

        $this->assertTrue($us8['held_before'], 'Before still shows the hold.');
        $this->assertFalse($us8['held_after'], 'After shows it cleared.');
        // And the mark is the same string in both columns, because it is the
        // same stored row.
        $this->assertNotEmpty($us8['remark']);
    }

    public function test_the_commission_screens_are_reachable(): void
    {
        $this->actingAs($this->memberOne)
            ->get(route('cgpsc.circulations.index'))->assertOk();

        $this->actingAs($this->memberOne)
            ->get(route('cgpsc.circulations.show', $this->circulation))
            ->assertOk()
            ->assertSee('US8');
    }

    public function test_the_api_records_a_decision_and_signs(): void
    {
        $item = $this->circulation->items()->firstOrFail();

        $this->actingAs($this->memberOne)
            ->postJson(route('cgpsc.circulations.decide', [$this->circulation, $item]), ['decision' => 'consented'])
            ->assertOk()
            ->assertJsonPath('decision', 'consented');

        $this->actingAs($this->memberOne)
            ->postJson(route('cgpsc.circulations.consent', $this->circulation))
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->actingAs($this->memberOne)
            ->postJson(route('cgpsc.circulations.sign', $this->circulation))
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertNotNull($this->propertyHold()->fresh()->released_at);
    }

    public function test_a_member_who_did_not_conduct_the_dpc_is_refused_by_the_api(): void
    {
        $item = $this->circulation->items()->firstOrFail();

        $this->actingAs($this->memberTwo)
            ->postJson(route('cgpsc.circulations.decide', [$this->circulation, $item]), ['decision' => 'consented'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_phase_3_is_not_present(): void
    {
        // Phase 3 renders, stores, hashes, signs and publishes the prescribed
        // form. Phase 2 signs a statement of what was consented to; no
        // document table exists yet.
        $this->assertFalse(
            Schema::hasTable('dpc_circulation_documents'),
            'dpc_circulation_documents belongs to Phase 3.'
        );
    }
}
