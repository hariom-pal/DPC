<?php

declare(strict_types=1);

namespace Tests\Feature\CandidateVerification;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcEvaluationCrGrade;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Master\Models\RecordReason;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\CandidateVerification\Concerns\BuildsVerification;
use Tests\TestCase;

/**
 * 2026-11-22: the candidate sees which CR years are missing and the reason recorded for
 * each; and the CGPSC Seniority List link the Administrator records for each DPC is
 * shown to its candidates above the list uploaded to the DPC.
 */
class MissingCrAndCgpscLinkTest extends TestCase
{
    use BuildsVerification;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 11:30:00');
        $this->bootVerification(days: 10);
        $this->approveByAh()->assertSessionHasNoErrors();
        auth()->logout();
    }

    public function test_the_candidate_sees_each_missing_cr_year_and_its_reason(): void
    {
        $row = $this->row(1);
        $last = (int) ($this->proposal->dpc_year ?: $this->proposal->proposal_date->year) - 1;
        DpcEvaluationCrGrade::query()->updateOrCreate(['dpc_evaluation_row_id' => $row->id, 'year' => $last], ['put_up' => false, 'grade' => null]);
        $reason = RecordReason::query()->create(['code' => 'leave_m', 'name_en' => 'Long leave', 'name_hi' => 'दीर्घ अवकाश', 'for_cr' => true]);
        DpcEvaluationRecordGap::query()->create(['dpc_evaluation_row_id' => $row->id, 'kind' => DpcEvaluationRecordGap::KIND_CR, 'year' => $last, 'record_reason_id' => $reason->id, 'remarks' => 'On study leave']);

        $this->signIn(1);
        $html = (string) $this->get(route('candidate-verification.section', [$this->participant(1)->uuid, 'cr']))->assertOk()
            ->assertSee('id="cr-missing"', false)
            ->assertSee(__('candidate.verification.cr.missing_heading'))
            ->getContent();
        $this->assertMatchesRegularExpression('#<li data-missing-year="'.$last.'">\s*'.$last.' — '.preg_quote(__('candidate.verification.cr.missing_states.missing'), '#').':\s*Long leave \(On study leave\)#u', $html);
        // A year never entered says so, with no reason yet.
        $this->assertMatchesRegularExpression('#<li data-missing-year="'.($last - 1).'">\s*'.($last - 1).' — '.preg_quote(__('candidate.verification.cr.missing_states.not_recorded'), '#').':\s*'.preg_quote(__('candidate.verification.cr.missing_no_reason'), '#').'#u', $html);
    }

    public function test_no_missing_notice_when_every_cr_year_is_graded(): void
    {
        $row = $this->row(2);
        $last = (int) ($this->proposal->dpc_year ?: $this->proposal->proposal_date->year) - 1;
        foreach (range($last - 4, $last) as $year) {
            DpcEvaluationCrGrade::query()->updateOrCreate(['dpc_evaluation_row_id' => $row->id, 'year' => $year], ['put_up' => true, 'has_grading' => true, 'grade' => 'क']);
        }

        $this->signIn(2);
        $this->get(route('candidate-verification.section', [$this->participant(2)->uuid, 'cr']))->assertOk()->assertDontSee('id="cr-missing"', false);
    }

    public function test_the_administrator_records_each_dpcs_cgpsc_link_and_its_candidates_see_it(): void
    {
        $uuid = $this->participant(1)->uuid;

        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);
        $this->actingAs($admin)->get(route('admin.seniority-links.index', ['missing' => 1]))->assertOk()
            ->assertSee('data-proposal="'.$this->proposal->id.'"', false)
            ->assertSee(__('admin.seniority_links.pending'));

        // Only an https link is taken.
        $this->put(route('admin.seniority-links.update', $this->proposal), ['cgpsc_seniority_list_url' => 'javascript:alert(1)'])
            ->assertSessionHasErrorsIn('link'.$this->proposal->id, 'cgpsc_seniority_list_url');
        $this->put(route('admin.seniority-links.update', $this->proposal), ['cgpsc_seniority_list_url' => 'http://psc.cg.gov.in/list.pdf'])
            ->assertSessionHasErrorsIn('link'.$this->proposal->id, 'cgpsc_seniority_list_url');
        $this->assertNull($this->proposal->fresh()->cgpsc_seniority_list_url);

        $url = 'https://psc.cg.gov.in/seniority/he-us-2026.pdf';
        $this->put(route('admin.seniority-links.update', $this->proposal), ['cgpsc_seniority_list_url' => $url])->assertSessionHasNoErrors();
        $this->assertSame($url, $this->proposal->fresh()->cgpsc_seniority_list_url);
        $this->assertTrue(ActivityTimeline::query()->where('event', 'dpc_proposal.cgpsc_seniority_link_updated')->exists());
        $this->get(route('admin.seniority-links.index', ['missing' => 1]))->assertOk()->assertDontSee('data-proposal="'.$this->proposal->id.'"', false);
        $this->get(route('admin.seniority-links.index', ['q' => $this->proposal->dpc_code]))->assertOk()->assertSee($url, false);

        $this->signIn(1);
        $this->get(route('candidate-verification.section', [$uuid, 'seniority']))->assertOk()
            ->assertSee('id="cgpsc-seniority-link"', false)
            ->assertSee('href="'.$url.'" target="_blank"', false);

        // Removed: gone from the candidate's page too.
        $this->actingAs($admin)->put(route('admin.seniority-links.update', $this->proposal), ['cgpsc_seniority_list_url' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->proposal->fresh()->cgpsc_seniority_list_url);
    }

    public function test_without_a_link_the_candidate_sees_none(): void
    {
        $this->signIn(1);
        $this->get(route('candidate-verification.section', [$this->participant(1)->uuid, 'seniority']))->assertOk()->assertDontSee('id="cgpsc-seniority-link"', false);
    }

    public function test_only_the_administrator_reaches_the_links(): void
    {
        $this->actingAs($this->ah)->get(route('admin.seniority-links.index'))->assertForbidden();
        $this->actingAs($this->ao)->put(route('admin.seniority-links.update', $this->proposal), ['cgpsc_seniority_list_url' => 'https://example.gov.in'])->assertForbidden();
        $this->assertNull($this->proposal->fresh()->cgpsc_seniority_list_url);
    }
}
