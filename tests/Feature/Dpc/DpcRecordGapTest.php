<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\RecordReason;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RaisesDpcProposals;
use Tests\Concerns\SeedsEvaluationCharts;
use Tests\Feature\Eligibility\MeritGateTest;
use Tests\TestCase;

/**
 * Recording why a mandatory record is missing, through the screens (change register
 * of 2026-08-27, items 14 to 19).
 *
 * {@see MeritGateTest} proves a stored gap reaches the
 * engine. This class proves the officer can *create* one — the half the register
 * describes in its own words: *"AO shall upload Reason and Supporting PDF."*
 */
class DpcRecordGapTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;
    use SeedsEvaluationCharts;

    private Department $department;

    private User $ao;

    private DpcProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        Storage::fake('local');

        $this->department = Department::query()->create([
            'code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active',
        ]);
        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();

        $from = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Under Secretary',
            'class_level' => 'I', 'status' => 'active',
        ]);
        $to = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Deputy Secretary',
            'class_level' => 'I', 'status' => 'active',
        ]);

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.store'),
            $this->dpcStorePayload($this->sanctioningPreDpc($from->id, $to->id)),
        );
        $this->proposal = DpcProposal::query()->latest('id')->firstOrFail();

        $this->seedSeniorityAndChart($this->proposal, [[
            'sn' => 1,
            'name' => 'US1',
            'dob' => '1964-03-20',
            'reservation_category_id' => ReservationCategory::query()->where('code', 'UR')->firstOrFail()->id,
            'cr_grades' => [
                ['year' => 2014, 'grade' => 'क'], ['year' => 2015, 'grade' => 'क'],
                ['year' => 2016, 'grade' => 'क'], ['year' => 2017, 'grade' => 'क'],
            ],
            'loyalty_flags' => [
                ['year' => 2014, 'flag' => true], ['year' => 2015, 'flag' => true],
                ['year' => 2016, 'flag' => true], ['year' => 2017, 'flag' => true],
            ],
            'property_flags' => [
                ['year' => 2014, 'flag' => true], ['year' => 2015, 'flag' => true],
                ['year' => 2016, 'flag' => true], ['year' => 2017, 'flag' => true],
            ],
        ]]);
    }

    public function test_the_screen_lists_the_shortfall_and_the_reasons_on_offer(): void
    {
        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.record-gaps.index', [$this->proposal, $this->row()]))
            ->assertOk()
            ->assertSee('US1')
            // Four of the five mandatory CRs are recorded, so the screen says so
            // rather than leaving the officer to count.
            ->assertSee('4 of 5 mandatory CRs recorded.')
            ->assertSee('Record Not Available');
    }

    public function test_a_reason_and_a_pdf_record_the_gap(): void
    {
        $response = $this->actingAs($this->ao)->post(
            route('dpc-proposals.record-gaps.store', [$this->proposal, $this->row()]),
            [
                'kind' => DpcEvaluationRecordGap::KIND_CR,
                'year' => 2013,
                'record_reason_id' => $this->reason('record_not_available')->id,
                'document' => UploadedFile::fake()->create('cr-2013.pdf', 12, 'application/pdf'),
            ],
        );

        $response->assertRedirect();

        $gap = DpcEvaluationRecordGap::query()->firstOrFail();

        $this->assertSame(DpcEvaluationRecordGap::KIND_CR, $gap->kind);
        $this->assertSame(2013, $gap->year);
        $this->assertTrue($gap->hasDocument());
        $this->assertSame(64, strlen((string) $gap->sha256));
        $this->assertSame($this->ao->id, $gap->recorded_by);

        // Stored on the private disk, and readable back — a hash over a file that
        // was never written would certify nothing.
        Storage::disk('local')->assertExists((string) $gap->path);

        // And on the timeline, because a gap is what holds a promotion.
        $this->assertDatabaseHas(ActivityTimeline::class, [
            'event' => 'dpc_proposal.record_gap_recorded',
        ]);
    }

    /**
     * Item 19 makes the PDF mandatory, so a reason on its own is rejected at the
     * form rather than saved as a document-less gap.
     *
     * The alternative is the failure mode that matters: a gap the screen shows as
     * accounted for and the engine reads as unaccounted for.
     */
    public function test_a_reason_without_a_pdf_is_refused(): void
    {
        $this->actingAs($this->ao)->post(
            route('dpc-proposals.record-gaps.store', [$this->proposal, $this->row()]),
            [
                'kind' => DpcEvaluationRecordGap::KIND_CR,
                'year' => 2013,
                'record_reason_id' => $this->reason('record_not_available')->id,
            ],
        )->assertSessionHasErrors('document');

        $this->assertDatabaseCount(DpcEvaluationRecordGap::class, 0);
    }

    public function test_only_a_pdf_is_accepted(): void
    {
        $this->actingAs($this->ao)->post(
            route('dpc-proposals.record-gaps.store', [$this->proposal, $this->row()]),
            [
                'kind' => DpcEvaluationRecordGap::KIND_CR,
                'year' => 2013,
                'record_reason_id' => $this->reason('record_not_available')->id,
                'document' => UploadedFile::fake()->create('cr-2013.xlsx', 12),
            ],
        )->assertSessionHasErrors('document');
    }

    /**
     * Item 16: *"If 'Other' is selected, Remarks become mandatory."*
     *
     * Driven off the reason's own `requires_remarks` flag, so this is really testing
     * that the flag is honoured — which is what lets an Administrator mark *Court
     * Case* as needing the case number without a deployment.
     */
    public function test_remarks_are_mandatory_for_a_reason_that_demands_them(): void
    {
        $other = $this->reason('other');
        $this->assertTrue($other->requires_remarks, 'the seeded "Other" reason must demand remarks');

        $payload = [
            'kind' => DpcEvaluationRecordGap::KIND_CR,
            'year' => 2013,
            'record_reason_id' => $other->id,
            'document' => UploadedFile::fake()->create('cr-2013.pdf', 12, 'application/pdf'),
        ];

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.record-gaps.store', [$this->proposal, $this->row()]),
            $payload,
        )->assertSessionHasErrors('remarks');

        // The same submission with the particulars is accepted.
        $this->actingAs($this->ao)->post(
            route('dpc-proposals.record-gaps.store', [$this->proposal, $this->row()]),
            $payload + ['remarks' => 'Record destroyed in the 2019 flood; FIR enclosed.'],
        )->assertSessionDoesntHaveErrors();

        $this->assertSame(
            'Record destroyed in the 2019 flood; FIR enclosed.',
            DpcEvaluationRecordGap::query()->firstOrFail()->remarks,
        );
    }

    /**
     * A reason that does not demand remarks accepts a submission without them —
     * otherwise the flag would be decoration.
     */
    public function test_remarks_stay_optional_for_an_ordinary_reason(): void
    {
        $this->actingAs($this->ao)->post(
            route('dpc-proposals.record-gaps.store', [$this->proposal, $this->row()]),
            [
                'kind' => DpcEvaluationRecordGap::KIND_CR,
                'year' => 2013,
                'record_reason_id' => $this->reason('record_not_available')->id,
                'document' => UploadedFile::fake()->create('cr-2013.pdf', 12, 'application/pdf'),
            ],
        )->assertSessionDoesntHaveErrors();

        $this->assertNull(DpcEvaluationRecordGap::query()->firstOrFail()->remarks);
    }

    /**
     * Item 15 ties the Loyalty Certificate to a CR year, so a loyalty gap on a year
     * with no CR is refused.
     *
     * Otherwise the portal would hold a promotion for a certificate nobody ever
     * expected, in a year the officer had no report in.
     */
    public function test_a_loyalty_gap_needs_a_cr_year(): void
    {
        $this->actingAs($this->ao)->post(
            route('dpc-proposals.record-gaps.store', [$this->proposal, $this->row()]),
            [
                'kind' => DpcEvaluationRecordGap::KIND_LOYALTY,
                'year' => 2013,
                'record_reason_id' => $this->reason('department_delay')->id,
                'document' => UploadedFile::fake()->create('loyalty-2013.pdf', 12, 'application/pdf'),
            ],
        )->assertSessionHasErrors('year');
    }

    /**
     * Re-recording the same (kind, year) replaces it rather than adding a second.
     *
     * The unique index says so, and the behaviour matters: an officer correcting a
     * wrongly chosen reason should not have to withdraw first, and two live gaps for
     * one year would make the engine's count wrong.
     */
    public function test_re_recording_replaces_rather_than_duplicates(): void
    {
        foreach (['record_not_available', 'department_delay'] as $code) {
            $this->actingAs($this->ao)->post(
                route('dpc-proposals.record-gaps.store', [$this->proposal, $this->row()]),
                [
                    'kind' => DpcEvaluationRecordGap::KIND_CR,
                    'year' => 2013,
                    'record_reason_id' => $this->reason($code)->id,
                    'document' => UploadedFile::fake()->create('cr-2013.pdf', 12, 'application/pdf'),
                ],
            )->assertSessionDoesntHaveErrors();
        }

        $this->assertDatabaseCount(DpcEvaluationRecordGap::class, 1);
        $this->assertSame(
            $this->reason('department_delay')->id,
            DpcEvaluationRecordGap::query()->firstOrFail()->record_reason_id,
        );
    }

    public function test_a_suspension_needs_both_the_suspension_and_the_reinstatement_orders(): void
    {
        // Rule 3.2: the document follows the reason.
        $suspended = $this->reason('suspended');
        $this->assertTrue($suspended->requiresSecondDocument());
        $this->assertSame(['Suspension order', 'Reinstatement order'], [$suspended->document_label_en, $suspended->second_document_label_en]);
        $this->assertFalse($this->reason('nrc_issued')->requiresSecondDocument());

        $store = fn (array $files) => $this->actingAs($this->ao)->post(
            route('dpc-proposals.record-gaps.store', [$this->proposal, $this->row()]),
            ['kind' => DpcEvaluationRecordGap::KIND_CR, 'year' => 2013, 'record_reason_id' => $suspended->id, ...$files],
        );

        $store(['document' => UploadedFile::fake()->create('suspension.pdf', 12, 'application/pdf')])
            ->assertSessionHasErrors('second_document');
        $this->assertSame(0, DpcEvaluationRecordGap::query()->count());

        $store([
            'document' => UploadedFile::fake()->create('suspension.pdf', 12, 'application/pdf'),
            'second_document' => UploadedFile::fake()->create('reinstatement.pdf', 12, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $gap = DpcEvaluationRecordGap::query()->with('reason')->sole();
        $this->assertTrue($gap->isJustified());
        $this->assertSame('reinstatement.pdf', $gap->second_original_name);

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.record-gaps.download', [$this->proposal, $this->row(), $gap, 'part' => 'second']))
            ->assertOk();

        // A gap without its second order is not a justification.
        $gap->forceFill(['second_disk' => null, 'second_path' => null])->save();
        $this->assertFalse($gap->refresh()->isJustified());
    }

    public function test_a_withdrawn_reason_cannot_be_chosen(): void
    {
        $reason = $this->reason('record_not_available');
        $reason->forceFill(['status' => 'inactive'])->save();

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.record-gaps.store', [$this->proposal, $this->row()]),
            [
                'kind' => DpcEvaluationRecordGap::KIND_CR,
                'year' => 2013,
                'record_reason_id' => $reason->id,
                'document' => UploadedFile::fake()->create('cr-2013.pdf', 12, 'application/pdf'),
            ],
        )->assertSessionHasErrors('record_reason_id');
    }

    public function test_the_document_can_be_downloaded_and_the_gap_withdrawn(): void
    {
        $this->actingAs($this->ao)->post(
            route('dpc-proposals.record-gaps.store', [$this->proposal, $this->row()]),
            [
                'kind' => DpcEvaluationRecordGap::KIND_CR,
                'year' => 2013,
                'record_reason_id' => $this->reason('record_not_available')->id,
                'document' => UploadedFile::fake()->create('cr-2013.pdf', 12, 'application/pdf'),
            ],
        );

        $gap = DpcEvaluationRecordGap::query()->firstOrFail();

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.record-gaps.download', [$this->proposal, $this->row(), $gap]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->ao)
            ->delete(route('dpc-proposals.record-gaps.destroy', [$this->proposal, $this->row(), $gap]))
            ->assertRedirect();

        $this->assertDatabaseCount(DpcEvaluationRecordGap::class, 0);
        $this->assertDatabaseHas(ActivityTimeline::class, [
            'event' => 'dpc_proposal.record_gap_withdrawn',
        ]);
    }

    /**
     * A URL naming one proposal and another proposal's officer is a 404.
     *
     * Both models are resolved independently by the router, so without the scope
     * one department's officer would be reachable — and writable — through another's
     * proposal.
     */
    public function test_an_officer_from_another_proposal_is_not_reachable(): void
    {
        // A second real proposal, raised the way the first was — its own posts, and
        // no officers at all. Seniority number 1 exists on the first proposal only.
        $from = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Section Officer',
            'class_level' => 'II', 'status' => 'active',
        ]);
        $to = DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => 'Assistant Secretary',
            'class_level' => 'I', 'status' => 'active',
        ]);

        $this->actingAs($this->ao)->post(
            route('dpc-proposals.store'),
            $this->dpcStorePayload($this->sanctioningPreDpc($from->id, $to->id)),
        );
        $other = DpcProposal::query()->latest('id')->firstOrFail();

        $this->assertNotSame($this->proposal->id, $other->id);

        $this->actingAs($this->ao)
            ->get(route('dpc-proposals.record-gaps.index', [$other, $this->row()]))
            ->assertNotFound();
    }

    private function row(): int
    {
        // The route binds the officer by their seniority number, scoped to the
        // proposal — the chart's own identifier.
        return 1;
    }

    private function reason(string $code): RecordReason
    {
        return RecordReason::query()->where('code', $code)->firstOrFail();
    }
}
