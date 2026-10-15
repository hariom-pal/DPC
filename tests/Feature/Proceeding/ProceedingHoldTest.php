<?php

declare(strict_types=1);

namespace Tests\Feature\Proceeding;

use App\Domain\Eligibility\Enums\RemarkCode;
use App\Domain\Proceeding\Enums\CorrectableField;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Enums\HoldReason;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use App\Domain\Proceeding\Services\HoldService;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowException;
use OwenIt\Auditing\Models\Audit;
use RuntimeException;
use Tests\Feature\Proceeding\Concerns\BuildsProceedings;
use Tests\TestCase;

/**
 * Held promotions — `CIR` (PPT S45, Conventional deck C3) — M7 Phase 4.
 *
 * A hold is a **procedural reservation of the promotion action, not a verdict
 * on merit**. The PPT's own US8 is the proof: 20 marks, a Super Seat, and held
 * anyway on a missing property return. These tests pin that independence.
 */
class ProceedingHoldTest extends TestCase
{
    use BuildsProceedings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProceeding();
    }

    private function holds(): HoldService
    {
        return app(HoldService::class);
    }

    // ------------------------------------------------------------- detection

    public function test_an_officer_with_too_few_cr_years_is_held(): void
    {
        // PPT S33's US6: four CR grades on a six-column chart, marked CIR.
        $this->officer(6, 'US6', $this->urCategoryId(), [2016 => 'क', 2017 => 'क']);

        $this->holds()->detect($this->proceeding->fresh(), $this->memberOne);

        $hold = DpcProceedingHold::query()->where('sn', 6)
            ->where('reason_code', HoldReason::CrYearsMissing->value)->firstOrFail();

        $this->assertTrue($hold->is_automatic);
        $this->assertStringContainsString('2 of 5', (string) $hold->detail);
    }

    public function test_an_officer_with_a_missing_property_return_is_held(): void
    {
        // PPT S33's US8: 20 marks, Super Seat — and still CIR.
        $this->officer(8, 'US8', $this->stCategoryId(),
            [2013 => 'क+', 2014 => 'क+', 2015 => 'क+', 2016 => 'क+', 2017 => 'क+'],
            propertyMissingYears: [2016],
        );

        $this->holds()->detect($this->proceeding->fresh(), $this->memberOne);

        $hold = DpcProceedingHold::query()->where('sn', 8)
            ->where('reason_code', HoldReason::PropertyMissing->value)->firstOrFail();

        $this->assertStringContainsString('2016', (string) $hold->detail);
    }

    /**
     * A hold is raised by the proceedings subsystem, not by the engine (AQ-4).
     *
     * **This test changed with the change register of 2026-08-27, and the change is
     * the interesting part.** It used to assert `is_eligible === true`: a hold was
     * *independent* of merit because the frozen engine had no opinion about Property
     * Returns at all, so an officer could be top-scoring, Super-Seated and held all
     * at once. Item 13 gives the engine that opinion — a Property Return missing from
     * the mandatory continuous window is now `Rejected` — so the same officer is now
     * held *and* rejected.
     *
     * What survives is the independence that AQ-4 was actually about: **the hold is
     * detected from the records, not read off the engine's verdict.** The score is
     * still computed, Super Seat is still awarded on the marks (items 21 and 23 —
     * neither formula was touched), and the hold still fires. Only the final status
     * now reflects the register.
     *
     * That the two subsystems both act on one missing Property Return is recorded as
     * **BD-38**: whether item 13's rejection is meant to supersede the M6 hold, or sit
     * beside it, is a question for the Commission and not one to settle in a fixture.
     */
    public function test_a_hold_is_independent_of_merit(): void
    {
        $this->officer(8, 'US8', $this->stCategoryId(),
            [2013 => 'क+', 2014 => 'क+', 2015 => 'क+', 2016 => 'क+', 2017 => 'क+'],
            propertyMissingYears: [2016],
        );

        $this->calculate();

        $result = $this->latestRun()->results()->where('officer_name', 'US8')->firstOrFail();

        // The formula still ran, and still ran unmodified: 20 marks, Super Seat.
        $this->assertSame(20, $result->score_breakdown['total']);
        $this->assertTrue($result->is_super_seat);

        // But item 13 rejects the missing Property Return, whatever the marks say,
        // and the reason names the year so the officer can answer it.
        $this->assertFalse($result->is_eligible);
        $this->assertSame(RemarkCode::Rejected->value, $result->remark);

        $reasons = implode(' ', $result->verdict['failure_reasons']);
        $this->assertStringContainsString('Property Return', $reasons);
        $this->assertStringContainsString('2016', $reasons);

        // And the hold fired on its own, from the records rather than the verdict.
        $this->assertTrue($this->holds()->isHeld($this->proceeding->fresh(), 8));
    }

    public function test_a_complete_officer_is_not_held(): void
    {
        $this->officer(1, 'US1', $this->urCategoryId(),
            [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']);

        $this->holds()->detect($this->proceeding->fresh(), $this->memberOne);

        $this->assertFalse($this->holds()->isHeld($this->proceeding->fresh(), 1));
    }

    public function test_holds_are_detected_as_part_of_calculate(): void
    {
        // Holds and scores read the same effective chart, so they must move
        // together rather than drift apart.
        $this->officer(6, 'US6', $this->urCategoryId(), [2016 => 'क', 2017 => 'क']);

        $this->calculate();

        $this->assertTrue($this->holds()->isHeld($this->proceeding->fresh(), 6));
    }

    public function test_a_correction_clears_an_automatic_hold(): void
    {
        $this->officer(8, 'US8', $this->stCategoryId(),
            [2013 => 'क+', 2014 => 'क+', 2015 => 'क+', 2016 => 'क+', 2017 => 'क+'],
            propertyMissingYears: [2016],
        );
        $this->calculate();
        $this->assertTrue($this->holds()->isHeld($this->proceeding->fresh(), 8));

        $this->corrections()->record(
            $this->proceeding->fresh(), $this->memberOne, CorrectableField::PropertyFlag, 8, 2016, 'Y',
            'Immovable property return produced at the sitting.',
        );
        $this->calculate();

        $this->assertFalse($this->holds()->isHeld($this->proceeding->fresh(), 8));
        // Released, not deleted — the record of having been held survives.
        $this->assertSame(1, DpcProceedingHold::query()->where('sn', 8)->count());
        $this->assertNotNull(DpcProceedingHold::query()->where('sn', 8)->firstOrFail()->released_at);
    }

    public function test_detection_does_not_duplicate_an_existing_hold(): void
    {
        $this->officer(6, 'US6', $this->urCategoryId(), [2016 => 'क', 2017 => 'क']);

        $this->holds()->detect($this->proceeding->fresh(), $this->memberOne);
        $this->holds()->detect($this->proceeding->fresh(), $this->memberOne);
        $this->holds()->detect($this->proceeding->fresh(), $this->memberOne);

        $this->assertSame(1, DpcProceedingHold::query()->where('sn', 6)->count());
    }

    public function test_holds_can_be_switched_off_by_configuration(): void
    {
        // Q12 is formally open, so the trigger is configurable rather than
        // hardcoded.
        config()->set('dpc.proceeding.hold.enabled', false);
        $this->officer(6, 'US6', $this->urCategoryId(), [2016 => 'क']);

        $this->holds()->detect($this->proceeding->fresh(), $this->memberOne);

        $this->assertSame(0, DpcProceedingHold::query()->count());
    }

    public function test_the_cr_threshold_is_configurable(): void
    {
        config()->set('dpc.proceeding.hold.min_cr_years', 2);
        $this->officer(6, 'US6', $this->urCategoryId(), [2016 => 'क', 2017 => 'क']);

        $this->holds()->detect($this->proceeding->fresh(), $this->memberOne);

        $this->assertFalse($this->holds()->isHeld($this->proceeding->fresh(), 6));
    }

    // ---------------------------------------------------------------- manual

    public function test_the_commission_can_hold_a_promotion_of_its_own(): void
    {
        $this->officer(1, 'US1', $this->urCategoryId(),
            [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']);

        $this->actingAs($this->memberOne)
            ->post(route('cgpsc.proceedings.holds.store', $this->proceeding), [
                'sn' => 1,
                'detail' => 'Departmental enquiry reported orally at the sitting.',
            ])
            ->assertSessionHasNoErrors();

        $hold = DpcProceedingHold::query()->where('sn', 1)->firstOrFail();
        $this->assertFalse($hold->is_automatic);
        $this->assertSame(HoldReason::Manual, $hold->reason_code);
        $this->assertSame('Member One', $hold->recorded_by_name);
    }

    public function test_a_manual_hold_requires_a_reason(): void
    {
        $this->officer(1, 'US1', $this->urCategoryId(), [2017 => 'क']);

        $this->actingAs($this->memberOne)
            ->post(route('cgpsc.proceedings.holds.store', $this->proceeding), ['sn' => 1, 'detail' => ''])
            ->assertSessionHasErrors('detail');

        $this->assertSame(0, DpcProceedingHold::query()->where('reason_code', 'manual')->count());
    }

    public function test_a_manual_hold_can_be_released(): void
    {
        $this->officer(1, 'US1', $this->urCategoryId(), [2017 => 'क']);
        $hold = $this->holds()->record($this->proceeding, $this->memberOne, 1, 'Departmental enquiry pending.');

        $this->actingAs($this->memberOne)
            ->delete(route('cgpsc.proceedings.holds.destroy', [$this->proceeding, $hold]))
            ->assertSessionHasNoErrors();

        $this->assertNotNull($hold->fresh()->released_at);
    }

    public function test_an_automatic_hold_cannot_be_released_by_hand(): void
    {
        // Releasing it by hand would simply reappear on the next detection,
        // which is worse than refusing.
        $this->officer(6, 'US6', $this->urCategoryId(), [2017 => 'क']);
        $this->holds()->detect($this->proceeding->fresh(), $this->memberOne);
        $hold = DpcProceedingHold::query()->firstOrFail();

        $this->expectException(WorkflowException::class);
        $this->holds()->release($this->proceeding->fresh(), $this->memberOne, $hold);
    }

    public function test_a_manual_hold_is_not_cleared_by_detection(): void
    {
        $this->officer(1, 'US1', $this->urCategoryId(),
            [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']);
        $this->holds()->record($this->proceeding, $this->memberOne, 1, 'Departmental enquiry pending.');

        $this->holds()->detect($this->proceeding->fresh(), $this->memberOne);

        $this->assertTrue($this->holds()->isHeld($this->proceeding->fresh(), 1));
    }

    public function test_an_officer_cannot_be_held_twice_by_hand(): void
    {
        $this->officer(1, 'US1', $this->urCategoryId(), [2017 => 'क']);
        $this->holds()->record($this->proceeding, $this->memberOne, 1, 'Departmental enquiry pending.');

        $this->expectException(WorkflowException::class);
        $this->holds()->record($this->proceeding->fresh(), $this->memberOne, 1, 'Another reason entirely.');
    }

    public function test_an_unknown_officer_cannot_be_held(): void
    {
        $this->expectException(WorkflowException::class);
        $this->holds()->record($this->proceeding, $this->memberOne, 99, 'Departmental enquiry pending.');
    }

    // ------------------------------------------------------- record integrity

    public function test_a_hold_is_immutable(): void
    {
        $this->officer(1, 'US1', $this->urCategoryId(), [2017 => 'क']);
        $hold = $this->holds()->record($this->proceeding, $this->memberOne, 1, 'Departmental enquiry pending.');

        $this->expectException(RuntimeException::class);
        $hold->update(['detail' => 'A different reason entirely.']);
    }

    public function test_a_hold_cannot_be_deleted(): void
    {
        $this->officer(1, 'US1', $this->urCategoryId(), [2017 => 'क']);
        $hold = $this->holds()->record($this->proceeding, $this->memberOne, 1, 'Departmental enquiry pending.');

        $this->expectException(RuntimeException::class);
        $hold->delete();
    }

    public function test_a_hold_records_a_timeline_event_carrying_the_proceeding_uuid(): void
    {
        $this->officer(1, 'US1', $this->urCategoryId(), [2017 => 'क']);
        $this->holds()->record($this->proceeding, $this->memberOne, 1, 'Departmental enquiry pending.');

        $entry = ActivityTimeline::query()->where('event', 'dpc_proceeding.hold')->firstOrFail();
        $p = $entry->properties;

        $this->assertSame($this->proceeding->uuid, $p['proceeding_uuid']);
        $this->assertSame(1, $p['sn']);
        $this->assertSame('US1', $p['officer_name']);
        $this->assertSame(HoldReason::Manual->value, $p['reason_code']);
        $this->assertNotEmpty($p['hold_uuid']);
        $this->assertFalse($p['is_automatic']);
    }

    public function test_a_hold_is_audited_and_tagged_with_the_proceeding_uuid(): void
    {
        $this->officer(1, 'US1', $this->urCategoryId(), [2017 => 'क']);
        $this->holds()->record($this->proceeding, $this->memberOne, 1, 'Departmental enquiry pending.');

        $audit = Audit::query()
            ->where('auditable_type', (new DpcProceedingHold)->getMorphClass())->firstOrFail();

        $this->assertStringContainsString($this->proceeding->uuid, (string) $audit->tags);
    }

    public function test_holds_cannot_be_changed_after_the_sitting_concludes(): void
    {
        $this->officer(1, 'US1', $this->urCategoryId(), [2017 => 'क']);
        $this->proceeding->update(['status' => DpcProceedingStatus::Cancelled->value]);

        $this->expectException(WorkflowException::class);
        $this->holds()->record($this->proceeding->fresh(), $this->memberOne, 1, 'Departmental enquiry pending.');
    }

    public function test_another_member_cannot_hold_a_promotion(): void
    {
        $this->officer(1, 'US1', $this->urCategoryId(), [2017 => 'क']);

        $this->actingAs($this->memberTwo)
            ->post(route('cgpsc.proceedings.holds.store', $this->proceeding), [
                'sn' => 1, 'detail' => 'Departmental enquiry pending.',
            ])
            ->assertForbidden();
    }

    public function test_the_result_screen_marks_a_held_officer_cir(): void
    {
        // S34's "ST SS CIR" — the hold is composed onto the remark rather than
        // produced by the frozen engine.
        $this->officer(8, 'US8', $this->stCategoryId(),
            [2013 => 'क+', 2014 => 'क+', 2015 => 'क+', 2016 => 'क+', 2017 => 'क+'],
            propertyMissingYears: [2016],
        );
        $this->calculate();

        $this->actingAs($this->memberOne)
            ->get(route('cgpsc.proceedings.result', $this->proceeding))
            ->assertOk()
            ->assertSee('CIR')
            ->assertSee('Promotions Held');
    }
}
