<?php

declare(strict_types=1);

namespace Tests\Feature\EndToEnd;

use App\Domain\Authentication\Models\User;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Committee\Models\CgpscChairman;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use App\Domain\Dpc\Services\ProposalVacancyPosition;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Import\Models\ImportBatch;
use App\Domain\Master\Models\ClassMaster;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DepartmentPostChange;
use App\Domain\Master\Models\Designation;
use App\Domain\Master\Models\District;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\PayScale;
use App\Domain\Master\Models\Qualification;
use App\Domain\Master\Models\RecordReason;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\Master\Models\ReviewReason;
use App\Domain\Master\Models\SealedCoverReason;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use App\Domain\Proceeding\Models\DpcSealedCoverOpening;
use App\Domain\PromotionOrder\Models\DpcPromotionOrder;
use App\Domain\Review\Models\DpcReviewLink;
use App\Enums\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ReadsSentMail;
use Tests\Concerns\SignsAsDepartmentAdmin;
use Tests\TestCase;
use Throwable;

/**
 * The whole first DPC of a new department, from a fresh installation, through the
 * real routes (regression, 2026-10-27):
 *
 * master-only seed → Portal Super Admin (bootstrap command) → department, posts,
 * Class Master promotion rule, CGPSC Member → Department Admin by invitation →
 * Department Admin creates the AO and AH → their first login (e-mail OTP, password
 * change) → Pre-DPC (Recruitment Rule generated) → Vacancy record → DPC Proposal →
 * committee → both CSV uploads → Employee Details for every officer, Loyalty, Rule
 * 1.1 → submit → AH verification and approval → CGPSC allotment, schedule,
 * proceeding, calculation, result approval → promotion order.
 *
 * Each step names itself when it fails.
 */
class FullDpcConditionsTest extends TestCase
{
    use ReadsSentMail;
    use RefreshDatabase;
    use SignsAsDepartmentAdmin;

    private const FIXTURES = __DIR__.'/../../Fixtures/dpc/';

    private const PASSWORD = 'Dpc@Test2026';

    private int $pdfSerial = 0;

    private function step(string $name, callable $body): mixed
    {
        try {
            return $body();
        } catch (Throwable $e) {
            $this->fail("Step [{$name}] failed: ".$e->getMessage());
        }
    }

    /** @return list<string> */
    private function errors(): array
    {
        $bag = session('errors');

        return match (true) {
            $bag instanceof ViewErrorBag => $bag->getBag('default')->all(),
            is_array($bag) => array_map('strval', Arr::flatten($bag)),
            default => [],
        };
    }

    private function ok(TestResponse $response): TestResponse
    {
        $this->assertSame([], $this->errors(), 'Errors: '.implode(' | ', $this->errors()));
        $this->assertTrue($response->isRedirect() || $response->isOk(), 'HTTP '.$response->status());

        return $response;
    }

    private function pdf(string $name): UploadedFile
    {
        $this->pdfSerial++;

        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% dummy {$this->pdfSerial} {$name}\n%%EOF");
    }

    private function shownPassword(TestResponse $page): string
    {
        preg_match('#id="issued-password">([^<]+)<#', (string) $page->getContent(), $m);
        $this->assertNotEmpty($m, 'password page');

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    /** Sign in with a temporary password: e-mail OTP, then the forced change. */
    private function firstLogin(string $email, string $temporary): User
    {
        auth()->logout();
        $login = $this->post(route('login.store'), ['login' => $email, 'password' => $temporary]);

        // An AO, AH or Department Admin verifies identity (simulated DigiLocker) and
        // gives the mobile declaration first (2026-10-27); a Commission member does not.
        if ($login->headers->get('Location') === route('identity.show')) {
            $this->passIdentityAndDeclaration(User::query()->where('email', $email)->sole())->assertRedirect(route('verify.show'));
        } else {
            $login->assertRedirect(route('verify.show'));
        }

        $this->post(route('verify.store'), ['email_code' => '123456', 'mobile_code' => '123456'])->assertRedirect(route('dashboard'));
        $this->ok($this->post(route('password.change.update'), ['current_password' => $temporary, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]));
        auth()->logout();

        return User::query()->where('email', $email)->sole();
    }

    private const GRADES = [1 => ['क+', 'क+', 'क+', 'क+', 'क+'], 2 => ['क', 'क', 'क', 'क', 'क'], 3 => ['क', 'क', 'क', 'क', 'ख'], 4 => ['ख', 'ख', 'ख', 'ख', 'ख'], 5 => ['क+', 'क+', 'ग', 'क+', 'क+'], 6 => ['क', 'क', 'क', 'क', 'क'], 7 => ['क+', 'क+', 'क+', 'क+', 'क+'], 8 => ['ख', 'ख', 'ख', 'ख', 'क'], 9 => ['क', 'घ', 'क', 'क', 'क'], 10 => ['क', 'क', 'क', 'क', 'क']];

    /**
     * 1. Vacancy Management against the two lists: a Working Officers list that
     * disagrees with the record (one officer short, a C officer left out) keeps the
     * Seniority List closed and the proposal unsubmittable; the list that agrees opens it.
     */
    public function test_1_the_working_list_must_agree_with_vacancy_management(): void
    {
        $c = $this->dpc(['stop' => 'lists']);
        $upload = function (string $template, string $csv, array $extra = []) use ($c): ImportBatch {
            $copy = tempnam(sys_get_temp_dir(), 'e2e').'.csv';
            file_put_contents($copy, $csv);
            $this->actingAs($c['ao'])->post(route('dpc-proposals.imports.store', $c['proposal']), ['template' => $template, 'file' => new UploadedFile($copy, 'list.csv', 'text/csv', null, true)] + $extra);

            return ImportBatch::query()->latest('id')->firstOrFail();
        };
        $rows = array_map('trim', file(base_path('docs/new-dpc-guide/samples/working_officers_sample.csv')));
        $seniority = (string) file_get_contents(base_path('docs/new-dpc-guide/samples/seniority_list_sample.csv'));

        // EMP1007 (C, retiring) left out: imported, but the record does not match.
        $short = $upload('currently_working_us', implode('
', array_filter($rows, fn (string $r): bool => ! str_contains($r, 'EMP1007'))).'
');
        $this->actingAs($c['ao'])->post(route('dpc-proposals.imports.commit', [$c['proposal'], $short]));
        $rec = app(ProposalVacancyPosition::class)->reconcile($c['proposal']->fresh());
        $this->assertFalse($rec['matches']);
        $this->assertSame(['uploaded' => 7, 'record' => 8], $rec['differences']['total']);
        $this->assertSame('C', $rec['missing'][0]['kind']);

        // The Seniority List is refused until the lists agree; the proposal cannot be submitted.
        $this->actingAs($c['ao'])->post(route('dpc-proposals.imports.store', $c['proposal']), ['template' => 'final_seniority_list_us', 'seniority_list_as_on' => '2025-04-01', 'file' => UploadedFile::fake()->createWithContent('s.csv', $seniority)]);
        $this->assertSame(0, $c['proposal']->fresh()->seniorityEntries()->count());
        $this->actingAs($c['ao'])->post(route('dpc-proposals.submit', $c['proposal']))->assertSessionHasErrors('workflow');

        // The B2 officer marked HERE instead of OUT: that row is refused with the reason.
        $wrongSide = $upload('currently_working_us', implode('
', array_map(fn (string $r): string => str_contains($r, 'EMP1005') ? preg_replace('/,N,Y$/', ',Y,N', $r) : $r, $rows)).'
');
        $this->assertGreaterThan(0, (int) $wrongSide->rejected_count);

        // The list that agrees opens the Seniority List.
        $good = $upload('currently_working_us', implode('
', $rows).'
');
        $this->assertSame(0, (int) $good->rejected_count);
        $this->ok($this->actingAs($c['ao'])->post(route('dpc-proposals.imports.commit', [$c['proposal'], $good])));
        $this->assertTrue(app(ProposalVacancyPosition::class)->reconcile($c['proposal']->fresh())['matches']);
        $batch = $upload('final_seniority_list_us', $seniority, ['seniority_list_as_on' => '2025-04-01']);
        $this->assertSame(0, (int) $batch->rejected_count);
        $this->ok($this->actingAs($c['ao'])->post(route('dpc-proposals.imports.commit', [$c['proposal'], $batch])));
        $this->assertSame(10, $c['proposal']->fresh()->seniorityEntries()->count());
    }

    /**
     * 2. Promotion only (100 %): sanctioned 10, working 8 of whom B1 1, B2 1, C 2 →
     * A 2 + 1 + 1 + 2 = 6; the CR rule decides who is eligible and Super Seat goes first.
     */
    public function test_2_promotion_only_with_b1_b2_c_and_the_cr_rule(): void
    {
        $c = $this->dpc(['grades' => self::GRADES]);
        $b = $c['position']->fresh()->breakdown;
        $this->assertSame([8, 2, 1, 1, 2, 6, 6], [(int) $b['working']['total'], $b['clear_vacancy'], $b['deputation_in_vacancy'], $b['deputation_out_vacancy'], $b['anticipated_vacancy'], $b['total_vacancy'], (int) $c['position']->fresh()->promotion_vacancy]);

        $results = $this->results($this->conclude($c));
        $this->assertSame(['NE', 'NE'], [$results[5]->remark, $results[9]->remark], 'A year below the minimum, and the disqualifying grade.');
        $this->assertSame([true, true], [(bool) $results[1]->is_super_seat, (bool) $results[7]->is_super_seat]);
        $this->assertSame([1, 7, 2, 3, 4, 6], $this->order($c));
    }

    /**
     * 3. Promotion and Direct Recruitment: the Pre-DPC's share of the same total of 6.
     * 50 % → 3 promotion, 3 direct; 75 % → 4.5 rounds to 5 promotion (rule 1.3), 1 direct.
     */
    public function test_3a_half_promotion_half_direct_recruitment(): void
    {
        $c = $this->dpc(['pct' => 50]);
        $position = $c['position']->fresh();
        $this->assertSame([6, 3, 3], [(int) $position->breakdown['total_vacancy'], (int) $position->promotion_vacancy, (int) $position->direct_vacancy]);
        $this->conclude($c);
        $this->assertSame([1, 2, 3], $this->order($c), 'Only the promotion share is filled by this DPC.');
    }

    public function test_3b_three_quarters_promotion_rounds_half_up(): void
    {
        $c = $this->dpc(['pct' => 75]);
        $position = $c['position']->fresh();
        $this->assertSame([5, 1], [(int) $position->promotion_vacancy, (int) $position->direct_vacancy]);
        $this->conclude($c);
        $this->assertSame([1, 2, 3, 4, 5], $this->order($c));
    }

    /**
     * 4. Sealed cover: an officer with a departmental charge-sheet keeps the place on
     * the list (SCOVER) and is left out of the promotion order.
     */
    public function test_4_sealed_cover(): void
    {
        $c = $this->dpc(['sealed' => [2]]);
        $results = $this->results($this->conclude($c));
        $this->assertSame('SCOVER', $results[2]->remark);
        $this->assertNotContains(2, $this->order($c));
    }

    /**
     * 5. Mandatory Property Return Missing (2026-11-05): the AO marks 2023 Missing
     * with its reason and document; the AH sees the evidence; the officer is CIR, not
     * RJ, keeps the seat merit gives (reserved), and stays off the promotion order
     * until the circulation releases the hold.
     */
    public function test_5_mandatory_property_missing_is_cir_and_waits_for_the_circulation(): void
    {
        $c = $this->dpc(['no_property' => [3 => 2023]]);
        $row = $c['proposal']->evaluationRows()->where('sn', 3)->sole();
        $gap = $row->recordGaps()->where('kind', 'property')->where('year', 2023)->sole();
        $this->assertTrue($gap->isJustified());

        // The AH sees the missing record, its year, reason and document.
        $this->actingAs($c['ah'])->get(route('dpc-proposals.record-gaps.index', [$c['proposal'], $row]))->assertOk()
            ->assertSee(__('dpc.record_gaps.kind_property'))->assertSee('2023')->assertSee($gap->reason->label());

        $proceeding = $this->conclude($c);
        $results = $this->results($proceeding);
        $this->assertSame('CIR', $results[3]->status()->value, 'CIR, not RJ.');
        $hold = DpcProceedingHold::query()->where('dpc_proceeding_id', $proceeding->id)->where('sn', 3)->sole();
        $this->assertSame('property_missing', $hold->reason_code->value);

        // The seat is reserved (2026-11-05): not given to the next officer while held.
        $this->assertSame(['main', true], [$results[3]->list_type->value, (bool) $results[3]->is_eligible]);
        $this->assertSame([1, 2, 4, 5, 6], $this->order($c), 'Held: off the order, and nobody takes the seat.');

        $this->circulate($c, $hold);
        $this->assertSame(1, DpcPromotionOrder::query()->count(), 'The clearance issues no order by itself.');
        $this->assertSame([1, 2, 3, 4, 5, 6], $this->order($c), 'The order issued again promotes the cleared officer once.');
    }

    /**
     * 5c. A Property Return the rule leaves optional: missing it asks for no reason
     * or document and causes neither CIR nor RJ (2026-11-05).
     */
    public function test_5c_optional_property_missing_is_neither_cir_nor_rj(): void
    {
        $c = $this->dpc(['no_property' => [3 => 2023], 'optional_property_years' => [2023]]);
        $row = $c['proposal']->evaluationRows()->where('sn', 3)->sole();
        $this->assertSame(0, $row->recordGaps()->count(), 'No reason or document is asked for an optional year.');

        $proceeding = $this->conclude($c);
        $this->assertSame(['main', true], [$this->results($proceeding)[3]->list_type->value, (bool) $this->results($proceeding)[3]->is_eligible]);
        $this->assertSame(0, DpcProceedingHold::query()->where('dpc_proceeding_id', $proceeding->id)->count());
        $this->assertContains(3, $this->order($c));
    }

    /**
     * 7. Mandatory CR Missing (2026-11-05): the AO marks 2023 Missing with its reason
     * and document, no grade is invented, the officer is CIR not RJ and held, kept
     * off the promotion order; a circulation cannot clear a CR hold (BD-15), so the
     * officer stays off it — no marks are fabricated.
     */
    public function test_7_mandatory_cr_missing_is_cir_and_no_marks_are_invented(): void
    {
        $c = $this->dpc(['missing_cr' => [4 => 2023]]);
        $row = $c['proposal']->evaluationRows()->where('sn', 4)->sole();
        $this->assertNull($row->crGrades()->where('year', 2023)->value('grade'), 'No grade is invented.');
        $this->assertTrue($row->recordGaps()->where('kind', 'cr')->where('year', 2023)->sole()->isJustified());

        $proceeding = $this->conclude($c);
        $result = $this->results($proceeding)[4];
        $this->assertSame('CIR', $result->status()->value, 'CIR, not RJ.');
        $this->assertArrayNotHasKey(2023, (array) ($result->score_breakdown['marks_by_year'] ?? []), 'The missing year carries no marks.');
        $hold = DpcProceedingHold::query()->where('dpc_proceeding_id', $proceeding->id)->where('sn', 4)->sole();
        $this->assertSame('cr_years_missing', $hold->reason_code->value);

        // The seat merit gives is reserved (2026-11-05): off the order, and nobody takes it.
        $this->assertSame(['main', true], [$result->list_type->value, (bool) $result->is_eligible]);
        $this->assertSame([1, 2, 3, 5, 6], $this->order($c));

        // The CR hold cannot be circulated (BD-15): no marks are invented by a clearance.
        $this->actingAs($c['ao'])->post(route('dpc-circulations.store'), [
            'origin_dpc_proposal_uuid' => $c['proposal']->uuid, 'proposal_date' => now()->toDateString(),
            'items' => [['hold_uuid' => $hold->uuid, 'resolution_detail' => 'CR still unavailable.', 'resolution_reference' => 'CR/1', 'resolution_received_on' => now()->subDay()->toDateString()]],
        ]);
        $this->assertSame(0, DpcCirculation::query()->count());
        $this->assertNull($hold->fresh()->released_at);
        $this->assertNotContains(4, $this->order($c));
        $originalResult = $result->fresh()->getAttributes();

        // The actual CR arrives: the officer is reassessed through a Review DPC, where
        // the AO records it and the DPC is calculated again.
        $review = $this->approvedReview($c, function (DpcProposal $review) use ($c): void {
            $row = $review->evaluationRows()->where('sn', 4)->sole();
            $route = fn (string $n): string => route('dpc-proposals.employees.'.$n, [$review, $row]);
            $this->ok($this->actingAs($c['ao'])->post($route('cr.save'), ['year' => 2023, 'put_up' => '1', 'has_grading' => '1', 'authorities' => ['reporter', 'reviewer', 'accepting'], 'grade' => 'क']));
            $this->ok($this->actingAs($c['ao'])->post($route('documents.store'), ['verified' => '1', 'kind' => 'cr_report', 'year' => 2023, 'undertaking' => '1', 'document' => $this->pdf('cr4-2023.pdf')]));
        });
        $reviewed = $this->results($this->conclude(['proposal' => $review] + $c))[4];
        $this->assertSame(7, (int) $reviewed->score_breakdown['marks_by_year'][2023], 'Marks from the CR actually recorded.');
        $this->assertNotSame('CIR', $reviewed->status()->value);
        $this->assertContains(4, $this->order(['proposal' => $review] + $c));

        // The original DPC's approved result is history, unchanged.
        $this->assertSame($originalResult, $result->fresh()->getAttributes());
    }

    /**
     * A Review DPC raised from the concluded DPC, its Rule 1.1 verified afresh, the
     * AO's corrections made, submitted and approved.
     */
    private function approvedReview(array $c, callable $correct): DpcProposal
    {
        return $this->step('Review DPC', function () use ($c, $correct): DpcProposal {
            $this->ok($this->actingAs($c['ao'])->post(route('dpc-proposals.reviews.store'), [
                'original_dpc_proposal_uuid' => $c['proposal']->uuid, 'proposal_date' => now()->toDateString(),
                'reasons' => [ReviewReason::query()->where('code', 'wrong_cr')->value('id')],
            ]));
            $review = DpcProposal::query()->where('original_dpc_proposal_id', $c['proposal']->id)->sole();
            $correct($review);
            foreach (['sanctioned_strength', 'recruitment_rule', 'seniority_list', 'original_documents', 'representations'] as $item) {
                $this->ok($this->actingAs($c['ao'])->post(route('dpc-proposals.verification.store', [$review, $item]), ['confirm' => '1']));
            }
            $this->ok($this->actingAs($c['ao'])->post(route('dpc-proposals.verification.store', [$review, 'previous_dpc_reconciliation']), ['confirm' => '1', 'previous_dpc_basis' => 'none', 'remarks' => 'Review of the DPC of this post.']));
            $this->ok($this->actingAs($c['ao'])->post(route('dpc-proposals.verification.store', [$review, 'recruitment_rule_conformity']), ['confirm' => '1', 'rr_notified_on' => '2026-01-01']));
            $this->ok($this->actingAs($c['ao'])->post(route('dpc-proposals.submit', $review)));
            foreach ($review->evaluationRows()->get() as $row) {
                $this->ok($this->actingAs($c['ah'])->post(route('dpc-proposals.employees.documents.verify-all', [$review, $row])));
            }
            $this->ok($this->actingAs($c['ah'])->post(route('dpc-proposals.approve', $review)));

            return $review->fresh();
        });
    }

    /**
     * 5b. CIR on an officer the rule does not refuse: the Member holds the officer
     * (a manual hold, e.g. a pending clarification). The officer keeps the place on
     * the Main List; the promotion waits for the circulation, and the order issued
     * again afterwards promotes them.
     */
    public function test_5b_a_held_officer_keeps_the_place_and_is_promoted_after_the_circulation(): void
    {
        $c = $this->dpc();
        $proceeding = $this->conclude($c, [3 => 'Integrity certificate awaited from the department.']);
        $results = $this->results($proceeding);
        $hold = DpcProceedingHold::query()->where('dpc_proceeding_id', $proceeding->id)->where('sn', 3)->sole();

        $this->assertSame(['main', 3], [$results[3]->list_type->value, (int) $results[3]->list_position]);

        // The promotion action is reserved until the circulation is signed.
        $this->assertSame([1, 2, 4, 5, 6], $this->order($c));
        $this->circulate($c, $hold);
        $this->assertSame([1, 2, 3, 4, 5, 6], $this->order($c), 'Issued again after the circulation.');
    }

    /**
     * 6. Review DPC: raised from the concluded DPC with a reason, it runs the same
     * flow, and its approved result supersedes the original.
     */
    public function test_6_review_supersedes_the_original(): void
    {
        $c = $this->dpc();
        $this->conclude($c);

        $this->ok($this->actingAs($c['ao'])->post(route('dpc-proposals.reviews.store'), [
            'original_dpc_proposal_uuid' => $c['proposal']->uuid, 'proposal_date' => now()->toDateString(),
            'reasons' => [ReviewReason::query()->where('code', 'wrong_cr')->value('id')],
        ]));
        $review = DpcProposal::query()->where('original_dpc_proposal_id', $c['proposal']->id)->sole();
        $this->assertSame('review', $review->dpcType?->code);

        // Rule 1.1 is verified afresh on the review (2026-11-05): nothing carries over,
        // the original's record is shown for reference only, and it is not touched.
        $originalVerifications = $c['proposal']->verifications()->count();
        $this->assertGreaterThan(0, $originalVerifications);
        $this->assertSame(0, $review->verifications()->count());
        $this->actingAs($c['ao'])->post(route('dpc-proposals.submit', $review))->assertSessionHasErrors('workflow');
        $this->actingAs($c['ao'])->get(route('dpc-proposals.verification', $review))->assertOk()->assertSee('id="original-verification"', false);

        // Rule 1.1 is confirmed again on the review, as on any proposal.
        foreach (['sanctioned_strength', 'recruitment_rule', 'seniority_list', 'original_documents', 'representations'] as $item) {
            $this->ok($this->actingAs($c['ao'])->post(route('dpc-proposals.verification.store', [$review, $item]), ['confirm' => '1']));
        }
        $this->ok($this->actingAs($c['ao'])->post(route('dpc-proposals.verification.store', [$review, 'previous_dpc_reconciliation']), ['confirm' => '1', 'previous_dpc_basis' => 'none', 'remarks' => 'Review of the DPC of this post.']));
        $this->ok($this->actingAs($c['ao'])->post(route('dpc-proposals.verification.store', [$review, 'recruitment_rule_conformity']), ['confirm' => '1', 'rr_notified_on' => '2026-01-01']));
        $this->ok($this->actingAs($c['ao'])->post(route('dpc-proposals.submit', $review)));
        foreach ($review->evaluationRows()->get() as $row) {
            $this->ok($this->actingAs($c['ah'])->post(route('dpc-proposals.employees.documents.verify-all', [$review, $row])));
        }
        $this->ok($this->actingAs($c['ah'])->post(route('dpc-proposals.approve', $review)));
        $this->conclude(['proposal' => $review->fresh()] + $c);

        $this->assertNotNull(DpcReviewLink::query()->where('original_dpc_proposal_id', $c['proposal']->id)->value('superseded_at'));
        $this->assertSame($originalVerifications, $c['proposal']->verifications()->count(), 'The original DPC\'s history is unchanged.');
    }

    /** The DPC's Chairman opens a sealed cover with the competent authority's decision. */
    private function openCover(array $c, DpcProceeding $proceeding, int $sn, string $outcome, string $openedOn): TestResponse
    {
        return $this->actingAs($c['member'])->post(route('cgpsc.proceedings.sealed-covers.open', $proceeding), [
            'sn' => $sn, 'outcome' => $outcome, 'opened_on' => $openedOn,
            'authority_name' => 'Secretary, PHE Department', 'authority_order_number' => 'PHE/VIG/'.$sn, 'authority_order_date' => $openedOn,
            'remarks' => 'Departmental enquiry concluded.', 'document' => $this->pdf("vig-order-{$sn}.pdf"),
        ]);
    }

    /** The AH issues the order an opening leads to. */
    private function followUp(array $c, DpcSealedCoverOpening $opening): TestResponse
    {
        return $this->actingAs($c['ah'])->post(route('dpc-proposals.promotion-orders.sealed-cover', [$c['proposal'], $opening]), [
            'order_number' => 'F-3/2026/PHE/SC/'.$opening->sn, 'order_date' => now()->toDateString(), 'confirm' => '1',
        ]);
    }

    /**
     * 9. Sealed cover — cleared (2026-11-05): only the DPC's Chairman opens it; a new
     * order promotes the officer from the opening date, never backdated; the DPC's
     * own order and the decision stay as they were.
     */
    public function test_9_sealed_cover_cleared_gives_a_new_order_from_the_opening_date(): void
    {
        $c = $this->dpc(['sealed' => [2]]);
        $proceeding = $this->conclude($c);
        $regular = $this->order($c);
        $this->assertNotContains(2, $regular);
        $regularOrder = DpcPromotionOrder::query()->sole();

        // Nobody but the DPC's Chairman: not the CGPSC Chairman, not the AH.
        $this->actingAs($c['chairman'])->get(route('cgpsc.proceedings.sealed-covers', $proceeding))->assertForbidden();
        $this->actingAs($c['ah'])->post(route('cgpsc.proceedings.sealed-covers.open', $proceeding), ['sn' => 2])->assertForbidden();
        $this->actingAs($c['member'])->get(route('cgpsc.proceedings.sealed-covers', $proceeding))->assertOk()->assertSee('id="sealed-cover-2"', false);

        $this->ok($this->openCover($c, $proceeding, 2, 'cleared', '2026-11-02'));
        $opening = DpcSealedCoverOpening::query()->sole();
        $this->assertSame(['cleared', '2026-11-02', 'PHE/VIG/2'], [$opening->outcome, $opening->opened_on->toDateString(), $opening->authority_order_number]);
        $this->openCover($c, $proceeding, 2, 'rejected', '2026-11-03')->assertSessionHasErrors('sealed_cover');

        $this->ok($this->followUp($c, $opening));
        $order = DpcPromotionOrder::query()->where('kind', DpcPromotionOrder::KIND_SEALED_COVER)->sole();
        $this->assertSame([[2], '2026-11-02'], [array_column((array) $order->officers, 'sn'), $order->effective_date?->toDateString()]);
        $this->assertStringContainsString('the date the sealed cover was opened', strip_tags((string) view('documents.promotion-order', ['order' => $order, 'proposal' => $c['proposal'], 'proceeding' => $proceeding, 'previous' => null, 'issuer' => $c['ah']])->render()));
        $this->assertSame('issued', $regularOrder->fresh()->status, 'The DPC\'s own order stands.');
        $this->assertSame('SCOVER', $this->results($proceeding)[2]->status()->value, 'The approved result is not rewritten.');
    }

    /**
     * 10. Sealed cover — rejected within the DPC year: the seat is offered once to
     * the next eligible candidate on the waiting list, whose order takes effect from
     * its date of issue; nobody is promoted twice.
     */
    public function test_10_sealed_cover_rejected_before_year_end_offers_the_seat_once(): void
    {
        $c = $this->dpc(['sealed' => [2]]);
        $proceeding = $this->conclude($c);
        $this->order($c);
        $waiting = collect($this->results($proceeding))->filter(fn ($r): bool => $r->list_type->value === 'waiting')->sortBy('list_position')->first();
        $this->assertNotNull($waiting, 'There is a waiting list to offer the seat from.');

        $this->ok($this->openCover($c, $proceeding, 2, 'rejected', '2026-11-02'));
        $opening = DpcSealedCoverOpening::query()->sole();
        $this->assertSame(['offered', (int) $waiting->sn], [$opening->replacement_status, $opening->replacement_sn]);

        $this->ok($this->followUp($c, $opening));
        $replacement = DpcPromotionOrder::query()->where('kind', DpcPromotionOrder::KIND_SEALED_COVER)->sole();
        $this->assertSame([(int) $waiting->sn], array_column((array) $replacement->officers, 'sn'));
        // Effective from the day this order is issued, never backdated (2026-11-05).
        $this->assertSame($replacement->order_date->toDateString(), $replacement->effective_date?->toDateString());

        // Issued again for the same opening it supersedes itself — never a second live order.
        $this->ok($this->followUp($c, $opening));
        $this->assertSame(1, DpcPromotionOrder::query()->where('kind', DpcPromotionOrder::KIND_SEALED_COVER)->where('status', 'issued')->count());

        // The DPC's own order issued again promotes neither the rejected officer nor the replacement again.
        $this->order($c);
        $everyone = DpcPromotionOrder::query()->where('status', 'issued')->get()->flatMap(fn (DpcPromotionOrder $o): array => array_column((array) $o->officers, 'sn'))->all();
        $this->assertSame(count($everyone), count(array_unique($everyone)), 'Nobody is on two orders.');
        $this->assertNotContains(2, $everyone);
    }

    /** 11. Rejected on 1 January after the DPC year: no promotion against the seat. */
    public function test_11_sealed_cover_rejected_on_1_january_after_the_year_promotes_nobody(): void
    {
        $c = $this->dpc(['sealed' => [2]]);
        $proceeding = $this->conclude($c);
        $this->travelTo('2027-01-05');

        $this->ok($this->openCover($c, $proceeding, 2, 'rejected', '2027-01-01'));
        $opening = DpcSealedCoverOpening::query()->sole();
        $this->assertSame(['not_after_year', null], [$opening->replacement_status, $opening->replacement_sn]);
        $this->followUp($c, $opening)->assertSessionHasErrors('order');
        $this->assertSame(0, DpcPromotionOrder::query()->where('kind', DpcPromotionOrder::KIND_SEALED_COVER)->count());
    }

    /**
     * 12. 30 and 31 December of the DPC year are within it (2026-11-05): the seat is
     * offered to the next eligible candidate.
     */
    public function test_12a_sealed_cover_rejected_on_30_december_offers_the_seat(): void
    {
        $this->assertOfferedWhenOpenedOn('2026-12-30');
    }

    public function test_12b_sealed_cover_rejected_on_31_december_offers_the_seat(): void
    {
        $this->assertOfferedWhenOpenedOn('2026-12-31');
    }

    private function assertOfferedWhenOpenedOn(string $date): void
    {
        $c = $this->dpc(['sealed' => [2]]);
        $proceeding = $this->conclude($c);
        $this->travelTo('2027-01-05');

        $this->ok($this->openCover($c, $proceeding, 2, 'rejected', $date));
        $opening = DpcSealedCoverOpening::query()->sole();
        $this->assertSame('offered', $opening->replacement_status);
        $this->assertNotNull($opening->replacement_sn);
        $this->ok($this->followUp($c, $opening));
        $this->assertSame('2027-01-05', DpcPromotionOrder::query()->where('kind', DpcPromotionOrder::KIND_SEALED_COVER)->sole()->effective_date?->toDateString());
    }

    /**
     * The DPC from a fresh installation to the AH's approval, shaped by `$o`:
     * promotion % (`pct`), the Vacancy Management record (`sanctioned`, `working`,
     * `entries`), the Working Officers list (`working_rows`), the CR grades
     * (`grades`), sealed covers (`sealed`, by SN) and property returns left out
     * (`no_property`, SN => year).
     *
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function dpc(array $o = []): array
    {
        $o += ['missing_cr' => [], 'optional_property_years' => [], 'stop' => null, 'pct' => 100, 'sanctioned' => 10, 'working' => 8, 'entries' => true, 'grades' => [], 'sealed' => [], 'no_property' => [], 'working_rows' => null];
        Storage::fake((string) config('dpc.documents.disk', 'local'));
        Storage::fake((string) config('dpc.imports.disk', 'local'));
        config(['dpc.reservation.category_wise' => false, 'dpc.vacancy_master.source_for_new_proposals' => 'vacancy_master']);

        // Fresh installation: master-only seed; the TestCase Chairman fixture removed.
        CgpscChairman::query()->delete();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(0, User::query()->count());

        $admin = $this->step('0 Portal Super Admin bootstrapped and signed in', function (): User {
            $this->artisan('dpc:bootstrap-super-admin', ['--name' => 'Portal Admin', '--email' => 'superadmin@cgpsc.gov.in', '--mobile' => '9000000001'])->assertSuccessful();
            $user = User::query()->sole();
            $user->forceFill(['email_verified_at' => now(), 'must_change_password' => false])->save();

            return $user;
        });

        $chairman = $this->step('0b Chairman account created and linked to the seeded Chairman record', function () use ($admin): User {
            $page = $this->actingAs($admin)->post(route('admin.users.store'), [
                'name' => 'Dr. Ajay Kumar Shrivastava', 'email' => 'chairman@cgpsc.gov.in', 'mobile' => '9000000002', 'role' => Role::Chairman->value,
            ]);
            $page->assertOk();
            $user = User::query()->where('email', 'chairman@cgpsc.gov.in')->sole();
            $user->forceFill(['email_verified_at' => now(), 'must_change_password' => false])->save();
            CgpscChairman::query()->serving()->sole()->forceFill(['user_id' => $user->id])->save();

            return $user;
        });

        // A department the master does not hold: the seeded ones (PHE among them)
        // already exist, and adding one is the Administrator's own step.
        $department = $this->step('1.1 Admin creates the department', function () use ($admin): Department {
            $this->ok($this->actingAs($admin)->post(route('admin.departments.store'), [
                'code' => 'PHEX', 'name' => 'Public Health Engineering Department (End-to-End)', 'status' => 'unregistered',
                'district_id' => District::query()->where('name_en', 'Raipur')->value('id'),
                'place' => 'Nava Raipur Atal Nagar', 'pin' => '492002', 'email' => 'phex.dpc@cg.gov.in',
            ]));

            return Department::query()->where('code', 'PHEX')->sole();
        });

        $this->step('1.2 designations', function () use ($admin): void {
            foreach (['Assistant Engineer', 'Executive Engineer', 'Superintending Engineer'] as $name) {
                $this->ok($this->actingAs($admin)->post(route('admin.designations.store'), ['name' => $name, 'status' => 'active']));
            }
        });

        $this->step('1.5 Promotion CR rule II -> I 2026', function () use ($admin, $o): void {
            $years = [];
            foreach (range(1, 5) as $before) {
                // A year the Administrator leaves optional for the Property Return (2026-11-05).
                $optional = in_array(2026 - $before, $o['optional_property_years'], true);
                $years[$before] = ['cr_min_marks' => 5, 'cr_mandatory' => '1', 'loyalty_mandatory' => '0', 'property_mandatory' => $optional ? '0' : '1'];
            }
            $this->ok($this->actingAs($admin)->post(route('admin.service-classes.promotion-rules.store', ClassMaster::query()->where('code', 'I')->value('id')), [
                'from_class' => 'II', 'effective_from_year' => 2026, 'cr_years' => 5, 'loyalty_years' => 0, 'property_years' => 5,
                'years' => $years, 'qualifying_total' => 25, 'super_seat_grade' => 9, 'tribal_bonus' => 0, 'tribal_bonus_skips_extremes' => '1',
            ]));
        });

        $member = $this->step('1.8 CGPSC Member account (password shown on screen)', function () use ($admin): User {
            $page = $this->actingAs($admin)->post(route('admin.users.store'), [
                'name' => 'Shri R. K. Member', 'email' => 'member.test@cgpsc.gov.in', 'mobile' => '9000000301', 'role' => 'member',
            ]);

            return $this->firstLogin('member.test@cgpsc.gov.in', $this->shownPassword($page));
        });

        [$ao, $ah, $deptAdmin, $preDpc, $from, $to] = $this->step('2 Invitation (department + e-mail) -> Department Admin (OTP + DigiLocker); AO maps the posts -> Department Admin opens and fills the Pre-DPC, then allots AO and AH', function () use ($admin, $department, $o): array {
            $designation = fn (string $name): int => (int) Designation::query()->where('name', $name)->value('id');
            // The department and its own e-mail (2026-10-28); both OTPs, the mobile
            // proved with the fixed code as on the local environment.
            config(['dpc.otp.fixed.mobile' => true]);
            $this->ok($this->actingAs($admin)->post(route('admin.department-invitations.store'), [
                'department_id' => $department->id, 'email' => 'phex.dpc@cg.gov.in',
            ]));
            $mail = collect($this->sentMail())->last(fn (array $m): bool => str_contains($m['body'], '/register/'));
            preg_match('#/register/([A-Za-z0-9]{64})#', $mail['body'], $m);
            $token = $m[1];

            // Mobile, both OTPs, DigiLocker, password.
            auth()->logout();
            $this->ok($this->post(route('register.invitation.start', ['token' => $token]), ['mobile' => '9000000401']));
            $this->ok($this->post(route('register.invitation.verify.store', ['token' => $token]), ['email_code' => '123456', 'mobile_code' => '123456']));
            $start = $this->post(route('register.invitation.identity.start', ['token' => $token]));
            parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
            $callback = $this->post(route('identity.simulator.submit'), [
                'state' => (string) $query['state'], 'decision' => 'allow',
                'name' => 'Shri D. Admin', 'date_of_birth' => '1972-08-01', 'gender' => 'M', 'aadhaar_verified' => '1',
            ]);
            $this->get((string) $callback->headers->get('Location'))->assertRedirect(route('register.invitation.password', ['token' => $token]));
            $this->ok($this->post(route('register.invitation.complete', ['token' => $token]), ['password' => 'Dept@Admin2026', 'password_confirmation' => 'Dept@Admin2026', 'designation_id' => (string) (Designation::query()->mappedIn($department->id)->value('id') ?? Designation::query()->firstOrCreate(['name' => 'Deputy Secretary'], ['status' => 'active'])->id)]));
            $deptAdmin = User::query()->where('email', 'phex.dpc@cg.gov.in')->sole();
            $this->assertSame('registered', $department->fresh()->status->value);
            $this->assertSame('digilocker_simulated', $deptAdmin->identity_verification_method);

            // The Department Admin's first sign-in: the mobile OTP.
            $this->post(route('login.store'), ['login' => 'phex.dpc@cg.gov.in', 'password' => 'Dept@Admin2026'])->assertRedirect(route('verify.show'));
            $this->passSignInChallenge()->assertRedirect(route('dashboard'));
            auth()->logout();

            // The department's officers, created by the CGPSC Administrator. No
            // DigiLocker for an AO/AH.
            $officers = [];
            foreach ([['ah.phe@cg.gov.in', 'Shri P. K. Head', '9000000302', 'Secretary', Role::AdministrativeHead, 'ah'], ['ao.phe@cg.gov.in', 'Shri S. K. Officer', '9000000303', 'Deputy Secretary', Role::AuthorizedOfficer, 'ao']] as [$email, $name, $mobile, $des, $role, $key]) {
                $page = $this->actingAs($admin)->post(route('admin.users.store'), [
                    'name' => $name, 'designation_id' => $designation($des), 'role' => $role->value, 'department_id' => $department->id,
                    'email' => $email, 'mobile' => $mobile,
                ]);
                $officers[$key] = $this->shownPassword($page);
            }
            $ao = User::query()->where('email', 'ao.phe@cg.gov.in')->sole();
            $ah = User::query()->where('email', 'ah.phe@cg.gov.in')->sole();
            $aoReady = $this->firstLogin('ao.phe@cg.gov.in', $officers['ao']);
            $ahReady = $this->firstLogin('ah.phe@cg.gov.in', $officers['ah']);

            // The AO requests the posts and the Department Admin approves them
            // (2026-11-03), before the Pre-DPC.
            foreach ([['Assistant Engineer', 'II', 'L12', 5], ['Executive Engineer', 'I', 'L13', null], ['Superintending Engineer', 'I', 'L14', null]] as [$name, $class, $scale, $duration]) {
                $this->ok($this->actingAs($aoReady)->post(route('post-mapping.store'), [
                    'department_id' => $department->id, 'designation_id' => Designation::query()->where('name', $name)->value('id'),
                    'class_level' => $class, 'pay_scale_id' => PayScale::query()->where('code', $scale)->value('id'),
                    'promotion_duration_years' => $duration, 'status' => 'active', 'effective_from' => '2026-01-01',
                ]));
            }
            foreach (DepartmentPostChange::query()->pending()->orderBy('id')->get() as $change) {
                $this->ok($this->actingAs($deptAdmin->fresh())->post(route('department.posts.changes.approve', $change)));
            }
            $from = DepartmentPost::query()->where('department_id', $department->id)->where('name', 'Assistant Engineer')->sole();
            $to = DepartmentPost::query()->where('department_id', $department->id)->where('name', 'Executive Engineer')->sole();
            // The Department Admin opens the Pre-DPC, fills all of it in, and
            // assigns its AO and AH as the last step (2026-10-28).
            $this->ok($this->actingAs($deptAdmin->fresh())->post(route('department.allotments.pre-dpc.store'), [
                'dpc_year' => 2026, 'promotion_to_post_id' => $to->id,
            ]));
            $preDpc = PreDpcProposal::query()->sole();
            $this->ok($this->put(route('pre-dpc.update', $preDpc), [
                'declaration' => '1',
                'dpc_year' => 2026, 'promotion_to_post_id' => $to->id,
                'promotion_sources' => [['promotion_from_post_id' => $from->id, 'promotion_percentage' => 100, 'min_experience_years' => 5,
                    'experience_unit' => 'years', 'qualification_ids' => [Qualification::query()->where('name_en', 'B.E.')->value('id')]]],
                'direct_recruitment_pct' => 100 - $o['pct'], 'promotion_recruitment_pct' => $o['pct'],
                'government_order_number' => 'F-1/2026/PHE/RR', 'effective_from' => '2026-01-01',
            ]));
            $this->ok($this->signedAllot($preDpc, ['ao_user_id' => $ao->id, 'ah_user_id' => $ah->id]));
            $this->assertSame([$ao->id, $ah->id], [$preDpc->fresh()->currentAllotment->ao_user_id, $preDpc->fresh()->currentAllotment->ah_user_id]);

            return [$aoReady, $ahReady, $deptAdmin, $preDpc, $from, $to];
        });

        $preDpc = $this->step('3 AO reviews and submits the Pre-DPC with eSign, AH approves with eSign; Recruitment Rule created', function () use ($ao, $ah, $preDpc, $to, $o): PreDpcProposal {
            $this->actingAs($ao)->get(route('pre-dpc.edit', $preDpc))->assertOk();
            $this->ok($this->actingAs($ao)->post(route('pre-dpc.submit', $preDpc), ['declaration' => '1']));
            $this->ok($this->actingAs($ah)->post(route('pre-dpc.approve', $preDpc)));
            $this->assertSame('approved', $preDpc->fresh()->status->value);
            $this->assertSame((float) $o['pct'], (float) RecruitmentRule::query()->where('post_id', $to->id)->sole()->promotion_recruitment_pct);

            return $preDpc->fresh();
        });

        $position = $this->step('4 Vacancy Management', function () use ($ao, $department, $to, $preDpc, $o): VacancyPosition {
            $this->ok($this->actingAs($ao)->post(route('vacancy-management.pre-dpc', $preDpc)));
            $params = [$department, 2026, $to];
            $this->ok($this->actingAs($ao)->post(route('vacancy-records.strength', $params), [
                'sanctioned_total' => $o['sanctioned'], 'working_total' => $o['working'],
                'order_number' => 'PHE/SANC/2026', 'order_date' => '2026-01-01', 'document' => $this->pdf('s.pdf'),
            ]));
            if ($o['entries']) {
                $this->ok($this->actingAs($ao)->post(route('vacancy-records.entries.store', $params), ['kind' => 'deputation_in', 'officer_name' => 'Shri Dinesh Kumar Patel', 'employee_number' => 'WRD2001', 'deputation_from' => '2024-07-01', 'deputation_to' => '2026-06-30', 'deputation_only_post' => '0']));
                $this->ok($this->actingAs($ao)->post(route('vacancy-records.entries.store', $params), ['kind' => 'deputation', 'officer_name' => 'Smt. Meena Baghel', 'employee_number' => 'EMP1005', 'deputation_from' => '2024-03-01', 'promotion_made' => '0']));
                $this->ok($this->actingAs($ao)->post(route('vacancy-records.entries.store', $params), ['kind' => 'anticipated', 'officer_name' => 'Shri Rajendra Prasad Sahu', 'employee_number' => 'EMP1007', 'reason' => 'retirement', 'effective_date' => '2026-08-31']));
                $se = DepartmentPost::query()->where('department_id', $department->id)->where('name', 'Superintending Engineer')->value('id');
                $this->ok($this->actingAs($ao)->post(route('vacancy-records.entries.store', $params), [
                    'kind' => 'anticipated', 'officer_name' => 'Shri Harish Kumar Netam', 'employee_number' => 'EMP1008', 'reason' => 'higher_post_promotion', 'effective_date' => '2026-05-01',
                    'order_number' => 'SE/PROMO/2026/1', 'order_date' => '2026-04-15', 'document' => $this->pdf('h.pdf'),
                    'higher_post_id' => $se, 'higher_dpc_reference' => 'SE-DPC-2026', 'higher_post_undertaking' => '1',
                ]));
            }
            $this->ok($this->actingAs($ao)->post(route('vacancy-records.finalize', $params)));

            return VacancyPosition::query()->sole();
        });

        $proposal = $this->step('5.1 DPC Proposal', function () use ($ao, $preDpc): DpcProposal {
            $this->ok($this->actingAs($ao)->post(route('dpc-proposals.store'), [
                'pre_dpc_proposal_id' => $preDpc->id, 'dpc_type_id' => DpcType::query()->where('code', 'fresh')->value('id'), 'legal_stay' => '0',
            ]));

            return DpcProposal::query()->sole();
        });

        $this->step('5.2 Committee', function () use ($ao, $proposal): void {
            $designation = fn (string $name): int => (int) Designation::query()->where('name', $name)->value('id');
            $this->ok($this->actingAs($ao)->put(route('dpc-proposals.committee.update', $proposal), ['members' => [
                ['name' => 'Shri R. K. Member', 'email' => 'member.test@cgpsc.gov.in', 'mobile' => '9000000301', 'designation_id' => $designation('Member, CGPSC'), 'capacity' => 'member', 'is_sc' => '1'],
                ['name' => 'Smt. A. Test', 'mobile' => '9000000311', 'designation_id' => $designation('Joint Secretary'), 'capacity' => 'member', 'is_st' => '1', 'is_female' => '1'],
                ['name' => 'Shri B. Test', 'mobile' => '9000000312', 'designation_id' => $designation('Deputy Secretary'), 'capacity' => 'member', 'is_obc' => '1', 'is_bhar_sadhak_sachiv' => '1'],
                ['name' => 'Shri C. Test', 'mobile' => '9000000313', 'designation_id' => $designation('Under Secretary'), 'capacity' => 'member', 'is_vibhag_sachiv' => '1'],
            ]]));
        });

        if ($o['stop'] === 'lists') {
            return compact('admin', 'chairman', 'member', 'department', 'ao', 'ah', 'deptAdmin', 'preDpc', 'from', 'to', 'position', 'proposal');
        }

        $this->step('6 CSV uploads', function () use ($ao, $proposal, $o): void {
            // The simple Working Officers list, downloaded from this DPC and filled in:
            // 5 of our officers (one of them away, B2) and the one here on deputation (B1),
            // both matched by Employee ID, which every row of the record carries (2026-11-02).
            $template = $this->actingAs($ao)->get(route('dpc-import-templates.download-for', [$proposal, 'currently_working_us']))->assertOk()->getContent();
            $lines = array_values(array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', (string) $template)), fn (string $line): bool => trim($line) !== ''));
            $this->assertSame('SN,"EMPLOYEE NO",NAME,DOB,MOBILE,EMAIL,"HERE ON DEPUTATION","OUT ON DEPUTATION"', trim($lines[0]));
            $working = $lines[0]."\n".implode("\n", $o['working_rows'] ?? array_slice(array_map('trim', file(base_path('docs/new-dpc-guide/samples/working_officers_sample.csv'))), 1))."\n";
            foreach ([['currently_working_us', 'working.csv', [], $working], ['final_seniority_list_us', 'seniority_list_10_officers.csv', ['seniority_list_as_on' => '2025-04-01'], file_get_contents(base_path('docs/new-dpc-guide/samples/seniority_list_sample.csv'))]] as [$template, $name, $extra, $contents]) {
                $copy = tempnam(sys_get_temp_dir(), 'e2e').'.csv';
                $contents === null ? copy(self::FIXTURES.$name, $copy) : file_put_contents($copy, $contents);
                $this->ok($this->actingAs($ao)->post(route('dpc-proposals.imports.store', $proposal), ['template' => $template, 'file' => new UploadedFile($copy, $name, 'text/csv', null, true)] + $extra));
                $batch = ImportBatch::query()->latest('id')->firstOrFail();
                $this->assertSame(0, (int) $batch->rejected_count);
                $this->ok($this->actingAs($ao)->post(route('dpc-proposals.imports.commit', [$proposal, $batch])));
            }
        });

        $this->step('7 Employee Details x10 + Loyalty + Rule 1.1', function () use ($ao, $proposal, $o): void {
            $route = fn (string $n, DpcEvaluationRow $r): string => route('dpc-proposals.employees.'.$n, [$proposal, $r]);
            foreach ($proposal->evaluationRows()->orderBy('sn')->get() as $row) {
                $this->ok($this->actingAs($ao)->put($route('basic', $row), ['dob_order_number' => 'SR/'.$row->sn, 'dob_order_date' => '1997-03-03', 'joining_order_number' => 'J/'.$row->sn, 'joining_order_date' => '1997-03-03', 'service_record_undertaking' => '1']));
                foreach ([2021, 2022, 2023, 2024, 2025] as $year) {
                    $missing = fn (string $name): array => ['missing_reason_id' => RecordReason::query()->where('code', 'record_not_available')->value('id') ?? RecordReason::query()->whereNull('second_document_label_en')->where('requires_remarks', false)->value('id'), 'missing_document' => $this->pdf($name)];
                    if (($o['missing_cr'][$row->sn] ?? null) === $year) {
                        // Missing: the reason and its supporting document, no grade (2026-11-05).
                        $this->ok($this->actingAs($ao)->post($route('cr.save', $row), ['year' => $year, 'put_up' => '0'] + $missing("mcr{$row->sn}{$year}.pdf")));
                    } else {
                        $this->ok($this->actingAs($ao)->post($route('cr.save', $row), ['year' => $year, 'put_up' => '1', 'has_grading' => '1', 'authorities' => ['reporter', 'reviewer', 'accepting'], 'grade' => $o['grades'][$row->sn][$year - 2021] ?? 'क']));
                        $this->ok($this->actingAs($ao)->post($route('documents.store', $row), ['verified' => '1', 'kind' => 'cr_report', 'year' => $year, 'undertaking' => '1', 'document' => $this->pdf("cr{$row->sn}{$year}.pdf")]));
                    }
                    if (($o['no_property'][$row->sn] ?? null) === $year) {
                        // Not submitted: Missing with its reason and document in a year the
                        // rule makes mandatory; nothing more in an optional one (2026-11-05).
                        $this->ok($this->actingAs($ao)->post($route('property.save', $row), ['year' => $year, 'submitted' => '0']
                            + (in_array($year, $o['optional_property_years'], true) ? [] : $missing("mipr{$row->sn}{$year}.pdf"))));
                    } else {
                        $this->ok($this->actingAs($ao)->post($route('property.save', $row), ['year' => $year, 'submitted' => '1', 'submitted_on' => ($year + 1).'-01-15', 'next_increment_date' => ($year + 1).'-07-01']));
                        $this->ok($this->actingAs($ao)->post($route('documents.store', $row), ['verified' => '1', 'kind' => 'property_return', 'year' => $year, 'undertaking' => '1', 'document' => $this->pdf("ipr{$row->sn}{$year}.pdf")]));
                    }
                }
                $this->ok($this->actingAs($ao)->post($route('documents.store', $row), ['verified' => '1', 'kind' => 'cr_channel', 'undertaking' => '1', 'document' => $this->pdf("ch{$row->sn}.pdf")]));
                $this->ok($this->actingAs($ao)->put($route('vigilance', $row), in_array($row->sn, $o['sealed'], true)
                    ? ['vigilance_pending' => '1', 'sealed_cover_reason_id' => SealedCoverReason::query()->where('code', 'de_chargesheet')->value('id'), 'vigilance_date' => '2026-03-01', 'vigilance_remarks' => 'Charge-sheet issued.']
                    : ['vigilance_pending' => '0']));
                if (in_array($row->sn, $o['sealed'], true)) {
                    $this->ok($this->actingAs($ao)->post($route('documents.store', $row), ['verified' => '1', 'kind' => 'vigilance_order', 'undertaking' => '1', 'document' => $this->pdf("vig{$row->sn}.pdf")]));
                }
                $this->ok($this->actingAs($ao)->put($route('ph', $row), ['is_ph' => '0']));
                $this->ok($this->actingAs($ao)->put($route('declarations', $row), ['unsanctioned_deputation' => '0', 'refused_promotion' => '0']));
                $this->assertSame([], array_filter(app(EmployeeDetailsService::class)->status($row->fresh())['sections']));
            }

            $rows = [];
            foreach ($proposal->evaluationRows()->with(['crGrades', 'propertyFlags'])->orderBy('sn')->get() as $row) {
                $rows[] = ['sn' => $row->sn,
                    'cr_grades' => $row->crGrades->map(fn ($c): array => ['year' => $c->year, 'grade' => $c->grade])->values()->all(),
                    'property_flags' => $row->propertyFlags->map(fn ($p): array => ['year' => $p->year, 'flag' => $p->flag ? '1' : '0'])->values()->all(),
                    'loyalty_flags' => array_map(fn (int $y): array => ['year' => $y, 'flag' => '1'], [2021, 2022, 2023, 2024, 2025])];
            }
            $this->ok($this->actingAs($ao)->put(route('dpc-proposals.evaluation-chart.update', $proposal), ['rows' => $rows]));

            foreach (['sanctioned_strength', 'recruitment_rule', 'seniority_list', 'original_documents', 'representations'] as $item) {
                $this->ok($this->actingAs($ao)->post(route('dpc-proposals.verification.store', [$proposal, $item]), ['confirm' => '1']));
            }
            $this->ok($this->actingAs($ao)->post(route('dpc-proposals.verification.store', [$proposal, 'previous_dpc_reconciliation']), ['confirm' => '1', 'previous_dpc_basis' => 'none', 'remarks' => 'No previous DPC for this post.']));
            $this->ok($this->actingAs($ao)->post(route('dpc-proposals.verification.store', [$proposal, 'recruitment_rule_conformity']), ['confirm' => '1', 'rr_notified_on' => '2026-01-01']));
            $this->assertSame([], app(DpcProposalCompletenessService::class)->missingSections($proposal->fresh()));
        });

        $this->step('8 Submit; AH verifies and approves', function () use ($ao, $ah, $proposal): void {
            $this->ok($this->actingAs($ao)->post(route('dpc-proposals.submit', $proposal)));
            foreach ($proposal->evaluationRows()->get() as $row) {
                $this->ok($this->actingAs($ah)->post(route('dpc-proposals.employees.documents.verify-all', [$proposal, $row])));
            }
            $this->ok($this->actingAs($ah)->post(route('dpc-proposals.approve', $proposal)));
            $this->assertSame('approved', $proposal->fresh()->status->value);
        });

        return compact('admin', 'chairman', 'member', 'department', 'ao', 'ah', 'deptAdmin', 'preDpc', 'from', 'to', 'position', 'proposal');
    }

    /** CIR: the AO raises a circulation for the hold, the AH approves, the Member consents and signs. */
    private function circulate(array $c, DpcProceedingHold $hold): DpcCirculation
    {
        return $this->step('CIR circulation', function () use ($c, $hold): DpcCirculation {
            $this->ok($this->actingAs($c['ao'])->post(route('dpc-circulations.store'), [
                'origin_dpc_proposal_uuid' => $c['proposal']->uuid, 'proposal_date' => now()->toDateString(),
                'items' => [['hold_uuid' => $hold->uuid, 'resolution_detail' => 'The ground of the hold is resolved.', 'resolution_reference' => 'RES/'.$hold->sn, 'resolution_received_on' => now()->subDay()->toDateString()]],
            ]));
            $circulation = DpcCirculation::query()->latest('id')->firstOrFail();
            $this->ok($this->actingAs($c['ao'])->post(route('dpc-circulations.submit', $circulation)));
            $this->ok($this->actingAs($c['ah'])->post(route('dpc-circulations.approve', $circulation)));
            foreach ($circulation->fresh()->items as $item) {
                $this->ok($this->actingAs($c['member'])->post(route('cgpsc.circulations.decide', [$circulation, $item]), ['decision' => 'consented']));
            }
            $this->ok($this->actingAs($c['member'])->post(route('cgpsc.circulations.consent', $circulation)));
            $this->ok($this->actingAs($c['member'])->post(route('cgpsc.circulations.sign', $circulation)));
            $this->assertSame('signed', $circulation->fresh()->status->value);
            $this->assertNotNull($hold->fresh()->released_at);

            return $circulation->fresh();
        });
    }

    /** CGPSC: allot, accept, schedule, open, calculate, and approve the result. */
    /** @param  array<int, string>  $holds  SN => the Member's reason for a manual hold (CIR) */
    private function conclude(array $c, array $holds = []): DpcProceeding
    {
        return $this->step('9 CGPSC: allot, accept, schedule, open, calculate, approve', function () use ($c, $holds): DpcProceeding {
            $seat = DpcCommitteeMember::query()->where('dpc_proposal_id', $c['proposal']->id)->where('user_id', $c['member']->id)->sole();
            $this->ok($this->actingAs($c['chairman'])->post(route('cgpsc.allotments.store', $c['proposal']), ['allotted_to' => $c['member']->id, 'dpc_chairman_member_id' => $seat->id, 'complete_by' => now()->addDays(30)->toDateString()]));
            $this->ok($this->actingAs($c['member'])->post(route('cgpsc.schedules.accept', $c['proposal'])));
            $this->ok($this->actingAs($c['member'])->post(route('cgpsc.schedules.store', $c['proposal']), ['dpc_at' => now()->addDays(2)->format('Y-m-d\TH:i')]));
            $this->ok($this->actingAs($c['member'])->post(route('cgpsc.proceedings.open', $c['proposal'])));
            $proceeding = DpcProceeding::query()->where('dpc_proposal_id', $c['proposal']->id)->sole();
            foreach ($holds as $sn => $detail) {
                $this->ok($this->actingAs($c['member'])->post(route('cgpsc.proceedings.holds.store', $proceeding), ['sn' => $sn, 'detail' => $detail]));
            }
            $this->ok($this->actingAs($c['member'])->post(route('cgpsc.proceedings.calculate', $proceeding)));
            $this->ok($this->actingAs($c['member'])->post(route('cgpsc.proceedings.approve', $proceeding), ['promotion_as_on_year' => 2026]));
            $this->assertSame('result_approved', $proceeding->fresh()->status->value);

            return $proceeding->fresh();
        });
    }

    /** @return array<int, DpcCalculationResult> the latest run's results by SN */
    private function results(DpcProceeding $proceeding): array
    {
        $run = DpcCalculationRun::query()->where('dpc_proposal_id', $proceeding->dpc_proposal_id)->latest('id')->firstOrFail();

        return DpcCalculationResult::query()->where('dpc_calculation_run_id', $run->id)->get()->keyBy('sn')->all();
    }

    /** @return list<int> the SNs on the promotion order */
    private function order(array $c): array
    {
        return $this->step('10 AH issues the promotion order', function () use ($c): array {
            $issued = DpcPromotionOrder::query()->where('dpc_proposal_id', $c['proposal']->id)->count();
            $this->ok($this->actingAs($c['ah'])->post(route('dpc-proposals.promotion-orders.store', $c['proposal']), ['order_number' => 'F-2/2026/PHE/PROMO/'.($issued + 1), 'order_date' => now()->toDateString(), 'confirm' => '1']));
            $order = DpcPromotionOrder::query()->where('dpc_proposal_id', $c['proposal']->id)->latest('id')->firstOrFail();
            $this->assertStringStartsWith('%PDF', (string) Storage::disk((string) $order->disk)->get((string) $order->path));

            return array_map(fn (array $officer): int => (int) $officer['sn'], (array) $order->officers);
        });
    }
}
