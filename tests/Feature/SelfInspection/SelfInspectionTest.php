<?php

declare(strict_types=1);

namespace Tests\Feature\SelfInspection;

use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\SelfInspection\Enums\SelfInspectionFactor;
use App\Domain\SelfInspection\Models\SelfInspectionAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Feature\SelfInspection\Concerns\BuildsInspectableCharts;
use Tests\TestCase;

/**
 * Self-Inspection & Assessment — PPT S49–S51. M10.
 *
 * The deck's three screens: the facility is introduced (S49), the officer
 * identifies themselves (S50), and their own row is shown with a deadline notice,
 * `CLOSE` and `PRINT` (S51).
 *
 * **What is asserted here is mostly what the screen refuses to do**, because this
 * is the only endpoint in the system an unauthenticated caller reaches with
 * officer data behind it, and the facts that open it all appear on a departmental
 * seniority list. Every test below that begins "cannot" or "does not" is load
 * bearing.
 */
class SelfInspectionTest extends TestCase
{
    use BuildsInspectableCharts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootChart();
    }

    // ------------------------------------------------------------------
    // S49 / S50 — reaching the facility
    // ------------------------------------------------------------------

    public function test_the_landing_and_identification_screens_are_open_without_an_account(): void
    {
        $this->get(route('self-inspection.landing'))
            ->assertOk()
            ->assertSee('Self-Inspection')
            ->assertSee('in person', escape: false);

        $this->get(route('self-inspection.identify'))
            ->assertOk()
            // The configured factors, rendered from configuration rather than
            // written into the view.
            ->assertSee(SelfInspectionFactor::Name->label())
            ->assertSee(SelfInspectionFactor::DateOfPostingOnPromotionFromPost->label())
            ->assertSee(SelfInspectionFactor::Dob->label());
    }

    /**
     * S49's own words: *"...विभागीय अधिकारीयों व कर्मचारियों को स्वयं के डाटा अवलोकन
     * की सुविधा"* — the facility is for viewing.
     */
    public function test_there_is_no_objection_route_because_the_deck_has_none(): void
    {
        // S51 has exactly two buttons. An objection endpoint would be a feature
        // the requirement does not contain (BD-20), so none is registered.
        $this->assertFalse(app('router')->has('self-inspection.objection'));
        $this->assertFalse(app('router')->has('self-inspection.objection.store'));

        $this->identify();

        $this->get(route('self-inspection.show'))
            ->assertOk()
            ->assertDontSee('Raise Objection')
            ->assertSee('Print')
            ->assertSee('Close');
    }

    // ------------------------------------------------------------------
    // S50 — identification
    // ------------------------------------------------------------------

    public function test_a_wrong_factor_refuses_and_says_nothing_about_which_one(): void
    {
        $answers = $this->correctFactors();
        $answers['dob'] = '1970-01-01';

        $response = $this->from(route('self-inspection.identify'))
            ->post(route('self-inspection.identify.store'), $answers);

        $response->assertRedirect(route('self-inspection.identify'));
        $response->assertSessionHasErrors('self_inspection');

        $message = (string) collect((array) session('errors')->get('self_inspection'))->first();
        $this->assertStringContainsString('No record under consideration matches', $message);

        // The refusal must not name the factor that failed: naming it turns the
        // screen into a lookup service one field at a time.
        foreach (['date of birth', 'Date of Birth', 'name', 'post'] as $leak) {
            $this->assertStringNotContainsString($leak, $message);
        }

        // And no session was opened.
        $this->get(route('self-inspection.show'))->assertRedirect(route('self-inspection.identify'));
    }

    /**
     * Only the two fields S50 shows would be a weaker login than any other actor
     * has, so the default asks for three. Dropping to two is a configuration
     * change — which is the point — and this asserts the default is the safer one.
     */
    public function test_the_name_and_joining_date_alone_are_not_enough_by_default(): void
    {
        $answers = $this->correctFactors();
        unset($answers['dob']);

        $this->post(route('self-inspection.identify.store'), $answers)
            ->assertSessionHasErrors('dob');
    }

    public function test_the_factor_set_is_configurable(): void
    {
        Config::set('dpc.self_inspection.authentication.factors', ['name', 'dob']);

        $this->get(route('self-inspection.identify'))
            ->assertOk()
            ->assertDontSee('Date of Joining Promotion To Post');

        $this->post(route('self-inspection.identify.store'), [
            'name' => $this->row->name,
            'dob' => '1964-03-20',
        ])->assertRedirect(route('self-inspection.show'));
    }

    /** Whitespace and case are normalised; the name itself is not matched loosely. */
    public function test_the_name_is_matched_case_and_whitespace_insensitively_but_not_partially(): void
    {
        $answers = $this->correctFactors();
        $answers['name'] = '  anil   KUMAR verma ';

        $this->post(route('self-inspection.identify.store'), $answers)
            ->assertRedirect(route('self-inspection.show'));

        $this->post(route('self-inspection.close'));

        // A prefix must not open the record — otherwise one guessed date opens
        // every officer whose name starts the same way.
        $partial = $this->correctFactors();
        $partial['name'] = 'Anil';

        $this->post(route('self-inspection.identify.store'), $partial)
            ->assertSessionHasErrors('self_inspection');
    }

    /**
     * A factor the department never recorded cannot be proved, and must not pass
     * by being empty on both sides.
     */
    public function test_a_factor_absent_from_the_record_cannot_be_satisfied(): void
    {
        $this->row->forceFill(['date_of_posting_on_promotion_from_post' => null])->save();

        $answers = $this->correctFactors();
        $answers['date_of_posting_on_promotion_from_post'] = '2012-01-01';

        $this->post(route('self-inspection.identify.store'), $answers)
            ->assertSessionHasErrors('self_inspection');
    }

    // ------------------------------------------------------------------
    // S51 — what the officer sees, and what they do not
    // ------------------------------------------------------------------

    public function test_the_screen_shows_the_s51_columns(): void
    {
        $this->identify();

        $this->get(route('self-inspection.show'))
            ->assertOk()
            ->assertSee('Anil Kumar Verma')
            ->assertSee('20-03-1964')          // DOB
            ->assertSee('MSC')                 // QUAL
            ->assertSee('01-01-2012')          // DOP
            ->assertSee('UR')                  // CAT
            ->assertSee('क+', escape: false)   // year-wise CR
            ->assertSee('ख', escape: false)
            ->assertSee('C.CASE')              // NOTE
            ->assertSee('2015')
            ->assertSee('2017');
    }

    /**
     * The screen shows the department's data, not the Committee's assessment.
     *
     * Marks and remarks are M5 outputs from a sitting that has not happened.
     * Rendering them here would publish a provisional promotion decision to the
     * officer it concerns, from an unsigned screen, before the Committee met.
     */
    public function test_the_screen_shows_no_mark_remark_or_decision(): void
    {
        $this->identify();

        $response = $this->get(route('self-inspection.show'))->assertOk();

        foreach (['MARK', 'REMARK', 'Waiting List', 'Main List', 'Eligible', 'Not Eligible', 'Super Seat'] as $absent) {
            $response->assertDontSee($absent);
        }
    }

    /**
     * A missing property return renders as absent, not as "no".
     *
     * A Circulation DPC exists precisely because an officer is missing a year, so
     * "not recorded" and "recorded as absent" are different facts — and the
     * officer looking at this screen is the person best placed to notice.
     */
    public function test_a_year_with_no_recorded_value_renders_as_absent(): void
    {
        $this->identify();

        $this->get(route('self-inspection.show'))
            ->assertOk()
            ->assertSee('—', escape: false);
    }

    public function test_print_renders_the_same_data_and_says_it_is_not_a_decision(): void
    {
        $this->identify();

        $this->get(route('self-inspection.print'))
            ->assertOk()
            ->assertSee('Anil Kumar Verma')
            ->assertSee('क+', escape: false)
            ->assertSee('not a promotion order', escape: false);
    }

    public function test_close_ends_the_session(): void
    {
        $this->identify();

        $this->post(route('self-inspection.close'))
            ->assertRedirect(route('self-inspection.landing'));

        $this->get(route('self-inspection.show'))->assertRedirect(route('self-inspection.identify'));
    }

    // ------------------------------------------------------------------
    // Reaching another officer's record — every route refused
    // ------------------------------------------------------------------

    public function test_an_unidentified_visitor_cannot_reach_any_record(): void
    {
        $this->get(route('self-inspection.show'))->assertRedirect(route('self-inspection.identify'));
        $this->get(route('self-inspection.print'))->assertRedirect(route('self-inspection.identify'));
    }

    /**
     * The `?row=` parameter picks between the officer's **own** matched rows and
     * cannot be pointed at anybody else's.
     */
    public function test_a_row_id_outside_the_matched_set_is_ignored(): void
    {
        $other = $this->makeRow($this->proposal, [
            'sn' => 2, 'name' => 'Sunita Rathore', 'dob' => '1971-05-09', 'employee_number' => 'EMP-002',
        ]);

        $this->identify();

        $this->get(route('self-inspection.show', ['row' => $other->id]))
            ->assertOk()
            ->assertSee('Anil Kumar Verma')
            ->assertDontSee('Sunita Rathore');
    }

    public function test_an_officer_on_two_proposals_sees_both_and_can_switch(): void
    {
        $second = $this->makeProposal(dpcCode: 'DPC-2026-SE-0002');
        $secondRow = $this->makeRow($second);

        $this->identify();

        $this->get(route('self-inspection.show'))
            ->assertOk()
            ->assertSee('DPC-2026-SE-0001')
            ->assertSee('DPC-2026-SE-0002');

        $this->get(route('self-inspection.show', ['row' => $secondRow->id]))
            ->assertOk()
            ->assertSee('DPC-2026-SE-0002');
    }

    // ------------------------------------------------------------------
    // When the facility is closed
    // ------------------------------------------------------------------

    public function test_a_draft_proposal_is_not_inspectable(): void
    {
        $this->proposal->forceFill(['status' => DpcProposalStatus::Draft->value])->save();

        $this->post(route('self-inspection.identify.store'), $this->correctFactors())
            ->assertSessionHasErrors('self_inspection');
    }

    public function test_the_visible_statuses_are_configurable(): void
    {
        $this->proposal->forceFill(['status' => DpcProposalStatus::Draft->value])->save();

        Config::set('dpc.self_inspection.visible_proposal_statuses', ['draft']);

        $this->post(route('self-inspection.identify.store'), $this->correctFactors())
            ->assertRedirect(route('self-inspection.show'));
    }

    public function test_the_whole_facility_can_be_switched_off(): void
    {
        Config::set('dpc.self_inspection.enabled', false);

        $this->get(route('self-inspection.landing'))
            ->assertOk()
            ->assertSee('currently closed');

        $this->post(route('self-inspection.identify.store'), $this->correctFactors())
            ->assertRedirect(route('self-inspection.identify'));
    }

    /**
     * An open session does not outlive the sitting.
     *
     * Inspectability is re-checked on every request rather than trusted from the
     * session, because a result can be approved while an officer has the screen
     * open — and after that, the invitation to correct the chart has expired.
     */
    public function test_a_session_closes_when_the_result_is_approved(): void
    {
        $this->identify();
        $this->get(route('self-inspection.show'))->assertOk();

        $this->approveResultOn($this->proposal->id);

        $this->get(route('self-inspection.show'))->assertRedirect(route('self-inspection.identify'));
    }

    public function test_closing_after_the_result_is_configurable(): void
    {
        $this->approveResultOn($this->proposal->id);

        Config::set('dpc.self_inspection.close_after_result', false);

        $this->post(route('self-inspection.identify.store'), $this->correctFactors())
            ->assertRedirect(route('self-inspection.show'));
    }

    // ------------------------------------------------------------------
    // Every attempt is recorded
    // ------------------------------------------------------------------

    public function test_a_successful_identification_is_recorded_against_the_row(): void
    {
        $this->identify();

        $access = SelfInspectionAccess::query()->where('action', 'identify')->firstOrFail();

        $this->assertTrue($access->matched);
        $this->assertSame($this->row->id, $access->dpc_evaluation_row_id);
        $this->assertSame($this->proposal->id, $access->dpc_proposal_id);
        $this->assertSame('identity_factors', $access->auth_driver);
        // The factor set in force is recorded: "this attempt passed" means
        // nothing later without knowing what it had to pass.
        // The configured set, not a literal: the third factor changed on
        // 2026-09-06 because a candidate awaiting promotion cannot answer the one it
        // replaced. See `dpc.self_inspection.authentication.factors`.
        $this->assertSame(
            array_map(static fn (SelfInspectionFactor $f): string => $f->value, SelfInspectionFactor::configured()),
            $access->factors_required,
        );
    }

    /** The failed attempt is the more valuable half of the record. */
    public function test_a_failed_attempt_is_recorded_with_no_row_attached(): void
    {
        $answers = $this->correctFactors();
        $answers['dob'] = '1970-01-01';

        $this->post(route('self-inspection.identify.store'), $answers);

        $access = SelfInspectionAccess::query()->firstOrFail();

        $this->assertFalse($access->matched);
        $this->assertNull($access->dpc_evaluation_row_id);
        $this->assertSame('anil kumar verma', $access->claimed_name);
    }

    /**
     * A failed guess at somebody's date of birth must not be stored, or this
     * table becomes the place to look them up.
     */
    public function test_a_failed_attempt_does_not_store_the_factor_answers(): void
    {
        $answers = $this->correctFactors();
        $answers['dob'] = '1970-01-01';

        $this->post(route('self-inspection.identify.store'), $answers);

        $stored = SelfInspectionAccess::query()->firstOrFail()->toArray();

        $this->assertStringNotContainsString('1970-01-01', json_encode($stored, JSON_THROW_ON_ERROR));
    }

    public function test_views_and_prints_are_recorded_separately(): void
    {
        $this->identify();
        $this->get(route('self-inspection.show'));
        $this->get(route('self-inspection.print'));

        $this->assertSame(1, SelfInspectionAccess::query()->where('action', 'identify')->count());
        $this->assertGreaterThanOrEqual(1, SelfInspectionAccess::query()->where('action', 'view')->count());
        $this->assertSame(1, SelfInspectionAccess::query()->where('action', 'print')->count());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function identify(): void
    {
        $this->post(route('self-inspection.identify.store'), $this->correctFactors())
            ->assertRedirect(route('self-inspection.show'));
    }

    /**
     * A proceeding with its result approved, built directly.
     *
     * The full M7 path is not driven here: this test needs the *fact* of an
     * approved result, and driving allotment → scheduling → sitting → calculate →
     * approve would be testing M7 inside an M10 test.
     */
    private function approveResultOn(int $proposalId): void
    {
        DpcProceeding::query()->create([
            'dpc_proposal_id' => $proposalId,
            'status' => DpcProceedingStatus::ResultApproved->value,
            'conducted_by_name' => 'Member One',
            'proposal_version' => str_repeat('a', 64),
            'opened_at' => now()->subDay(),
            'approved_at' => now(),
        ]);
    }
}
