<?php

declare(strict_types=1);

namespace Tests\Feature\Proceeding;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\SealedCoverReason;
use App\Domain\Proceeding\Models\DpcResultDocument;
use App\Domain\Proceeding\Models\DpcResultDocumentSignature;
use App\Domain\Proceeding\Services\ResultDocumentService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Proceeding\Concerns\BuildsProceedings;
use Tests\TestCase;

/**
 * The result documents (2026-11-25): when the result is approved, the Hindi
 * proceedings (कार्यवाही विवरण) and one sealed-cover recommendation for each officer in
 * a sealed cover are made as PDFs from the database; every committee member eSigns them,
 * in any order; once all have, each is made again with their signatures and is final,
 * and only then does the department read them.
 */
final class ResultDocumentTest extends TestCase
{
    use BuildsProceedings;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('dpc.documents.disk', 'local'));
        $this->bootProceeding();

        $this->officer(1, 'US1', $this->urCategoryId(), [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']);
        $this->officer(2, 'US2', $this->urCategoryId(), [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']);
        DpcEvaluationRow::query()->where('dpc_proposal_id', $this->proposal->id)->where('sn', 2)->update([
            'vigilance_pending' => true,
            'sealed_cover_reason_id' => SealedCoverReason::query()->where('code', 'de_chargesheet')->value('id'),
        ]);
    }

    /** @return TestResponse<Response> */
    private function approveOverHttp(): TestResponse
    {
        $this->calculate();

        return $this->actingAs($this->memberOne)->post(route('cgpsc.proceedings.approve', $this->proceeding), ['promotion_as_on_year' => 2017]);
    }

    private function service(): ResultDocumentService
    {
        return app(ResultDocumentService::class);
    }

    public function test_approving_the_result_makes_the_proceedings_and_a_sealed_cover_page_from_the_database(): void
    {
        $this->assertSame(0, DpcResultDocument::query()->count(), 'Nothing before the result is approved.');

        $this->approveOverHttp()->assertSessionHasNoErrors();

        $documents = $this->service()->documents($this->proceeding->fresh());
        $this->assertSame(['karyavahi', 'sealed_cover'], $documents->pluck('kind')->all());

        $karyavahi = $documents->first();
        $this->assertContains($karyavahi->format, ['class_1_to_1', 'other']);
        $this->assertSame('Higher Education Department', $karyavahi->data['department']);
        $this->assertSame([1], array_column($karyavahi->data['selection'], 'sn'), 'The selection list is the main list.');
        $this->assertSame(['US2'], array_column($karyavahi->data['sealed'], 'name'));
        $this->assertNotSame([], $karyavahi->data['members'], 'The committee that sat.');

        $cover = $documents->last();
        $this->assertSame([2, 'US2'], [$cover->sn, $cover->officer_name]);

        foreach ($documents as $document) {
            $pdf = Storage::disk($document->disk)->get($document->path);
            $this->assertStringStartsWith('%PDF', (string) $pdf);
            $this->assertSame(hash('sha256', (string) $pdf), $document->sha256);
            $this->assertNull($document->finalized_at);
        }

        // Made once: approving makes them, and asking again returns the same ones.
        $this->assertSame($documents->pluck('id')->all(), $this->service()->generate($this->proceeding->fresh())->pluck('id')->all());
    }

    public function test_every_member_esigns_in_any_order_and_the_last_makes_them_final(): void
    {
        $this->approveOverHttp();
        $proceeding = $this->proceeding->fresh();
        $signers = array_values(array_filter(array_column($this->service()->signers($proceeding), 'user_id')));
        $this->assertNotSame([], $signers);

        // Somebody off the committee neither reads nor signs.
        $stranger = User::factory()->create();
        $document = $this->service()->documents($proceeding)->first();
        $this->actingAs($stranger)->get(route('result-documents.show', [$proceeding, $document]))->assertForbidden();
        $this->post(route('result-documents.sign', $proceeding))->assertForbidden();

        foreach (array_reverse($signers) as $i => $userId) {
            $user = User::query()->findOrFail($userId);
            $this->actingAs($user)->get(route('result-documents.show', [$proceeding, $document]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->signThroughTheSimulator($user, $proceeding->proposal)->assertSessionHasNoErrors();

            $final = $i === count($signers) - 1;
            $this->assertSame($final, $this->service()->isFinal($proceeding->fresh()), $final ? 'The last eSign makes them final.' : 'Not before every member has signed.');

            // Once only.
            $this->assertNotNull($this->service()->signRefusal($proceeding->fresh(), $user));
        }

        $this->assertSame(count($signers), DpcResultDocumentSignature::query()->count());
        foreach ($this->service()->documents($proceeding->fresh()) as $signedDocument) {
            $this->assertNotNull($signedDocument->final_path);
            $this->assertNotSame($signedDocument->sha256, $signedDocument->final_sha256, 'The signed copy carries the signatures.');
            $this->assertStringStartsWith('%PDF', (string) Storage::disk($signedDocument->disk)->get((string) $signedDocument->final_path));
        }

        $this->expectException(WorkflowException::class);
        $this->service()->sign($proceeding->fresh(), User::query()->findOrFail($signers[0]));
    }

    public function test_the_department_reads_them_only_once_final(): void
    {
        $this->approveOverHttp();
        $proceeding = $this->proceeding->fresh();
        $document = $this->service()->documents($proceeding)->first();
        $department = User::factory()->create();
        $this->mock(ProposalAllotmentService::class, function ($mock): void {
            $mock->shouldReceive('isAllottedInAnyRole')->andReturn(true);
        });

        $this->assertFalse($this->service()->mayView($proceeding, $department));

        foreach (array_filter(array_column($this->service()->signers($proceeding), 'user_id')) as $userId) {
            $this->service()->sign($proceeding->fresh(), User::query()->findOrFail($userId));
        }

        $this->assertTrue($this->service()->mayView($proceeding->fresh(), $department));
        $this->actingAs($department)->get(route('result-documents.show', [$proceeding, $document]))->assertOk();
    }

    /**
     * The proceedings carry all eighteen paragraphs of the Commission's format, and the
     * nine-column vacancy table's six columns. Class I to I and other than Class I to I
     * print the same text: what differs is pending clarification, and no difference is
     * invented meanwhile.
     */
    public function test_the_proceedings_carry_every_paragraph_and_both_formats_print_the_same_text(): void
    {
        $this->approveOverHttp();
        $data = $this->service()->documents($this->proceeding->fresh())->first()->data;

        $html = fn (array $d): string => view('documents.result.karyavahi', ['d' => $d, 'signatures' => [], 'final' => false, 'digest' => null])->render();
        $page = $html($data);

        foreach ([
            'विभागीय पदोन्नति समिति की बैठक का कार्यवाही विवरण', 'उपस्थिति:-',
            '01 प्राधिकार एवं सिद्धांत', '02 न्यायालयीन स्थगन', '03 वरिष्ठता सूची', '04 विचार क्षेत्र', '05 संनिष्ठा प्रमाण पत्र',
            '06 अर्हकारी सेवा', '<span class="head">07</span>', '08 रिक्तियां', '09 रिक्त पदों का विवरण', '10.</span>', '11. संवर्ग में वर्तमान स्थिति',
            '12. पदोन्नति हेतु', '(अ) न्यूनतम कसौटी', '13. चयन सूचियां', '14. रिक्तियों को अग्रणित किया जाना', '15. अनुपयुक्तता सूचियां',
            '16. बंद लिफाफा में रखी गयी अनुशंसाएं', '17. परिभ्रमण के प्रकरण', '18. ऐसे अधिकारियों के नामों पर विचार/अनुशंसा नहीं किया गया',
            'अधिकारी का कोटा (पदोन्नति, सीधी भरती)', 'WP(PIL) No. 91/2019',
        ] as $expected) {
            $this->assertStringContainsString($expected, $page, $expected);
        }

        // Kandika 14 is not derived: a dash until it is clarified.
        $this->assertNull($data['carried_forward']);

        // The two formats: the same text, whichever this DPC is.
        $this->assertSame($html(array_replace($data, ['format' => 'class_1_to_1'])), $html(array_replace($data, ['format' => 'other'])));
    }

    /** The portal's record of who signed, never presented as a certificate-based digital signature. */
    public function test_the_signed_copy_says_it_is_a_portal_record_not_a_digital_certificate(): void
    {
        $this->approveOverHttp();
        $data = $this->service()->documents($this->proceeding->fresh())->first()->data;
        $userId = $data['members'][0]['user_id'];

        $final = view('documents.result.karyavahi', ['d' => $data, 'signatures' => [$userId => ['at' => '26.11.2026 10:00']], 'final' => true, 'digest' => str_repeat('a', 64)])->render();

        $this->assertStringContainsString('पोर्टल पर हस्ताक्षरित', $final);
        $this->assertStringContainsString('प्रमाणपत्र-आधारित डिजिटल हस्ताक्षर नहीं', $final);
        $this->assertStringNotContainsString('ई-हस्ताक्षरित', $final);
        $this->assertStringNotContainsString('DigiSign', $final);
    }

    /** What every member signed never changes: not the snapshot, not the file, not a signature. */
    public function test_a_document_and_its_signatures_cannot_be_changed_or_removed(): void
    {
        $this->approveOverHttp();
        $proceeding = $this->proceeding->fresh();
        $document = $this->service()->documents($proceeding)->first();

        foreach ([
            fn () => $document->fresh()->forceFill(['data' => ['department' => 'Tampered']])->save(),
            fn () => $document->fresh()->forceFill(['sha256' => str_repeat('0', 64)])->save(),
            fn () => $document->fresh()->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A result document was changed.');
            } catch (\RuntimeException) {
            }
        }

        $signers = array_values(array_filter(array_column($this->service()->signers($proceeding), 'user_id')));
        foreach ($signers as $userId) {
            $this->service()->sign($proceeding->fresh(), User::query()->findOrFail($userId));
        }
        $final = $document->fresh();
        $this->assertNotNull($final->finalized_at);

        foreach ([
            fn () => $final->forceFill(['final_path' => 'elsewhere.pdf'])->save(),
            fn () => DpcResultDocumentSignature::query()->firstOrFail()->forceFill(['member_name' => 'X'])->save(),
            fn () => DpcResultDocumentSignature::query()->firstOrFail()->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A final document or a signature was changed.');
            } catch (\RuntimeException) {
            }
        }
        $this->assertTrue($this->service()->verify($proceeding->fresh()));
    }

    /** A file altered on disk no longer matches its digest: it is not served, and the set does not verify. */
    public function test_a_tampered_file_is_refused(): void
    {
        $this->approveOverHttp();
        $proceeding = $this->proceeding->fresh();
        $document = $this->service()->documents($proceeding)->first();
        $member = User::query()->findOrFail($this->service()->signers($proceeding)[0]['user_id']);

        $this->assertTrue($this->service()->verify($proceeding));
        Storage::disk($document->disk)->put($document->path, '%PDF-1.4 tampered');

        $this->assertFalse($this->service()->verify($proceeding));
        $this->actingAs($member)->get(route('result-documents.show', [$proceeding, $document]))->assertStatus(409);
    }

    /** A member of the committee that sat without a login is awaited, never skipped: no final copy. */
    public function test_they_are_not_final_while_any_member_has_not_signed(): void
    {
        $this->approveOverHttp();
        $proceeding = $this->proceeding->fresh();
        $document = $this->service()->documents($proceeding)->first();
        $members = $document->data['members'];
        $this->assertGreaterThan(1, count($members));

        // Every member but the last signs.
        foreach (array_slice($members, 0, -1) as $member) {
            $this->service()->sign($proceeding->fresh(), User::query()->findOrFail($member['user_id']));
        }
        $this->assertFalse($this->service()->isFinal($proceeding->fresh()));
        $this->assertNull($document->fresh()->final_path);
    }

    /** 2026-11-27: a DPC Chairman without a seat heads the attendance and signs like every member. */
    public function test_a_dpc_chairman_without_a_seat_heads_the_attendance_and_signs(): void
    {
        DpcCommitteeMember::query()->where('dpc_proposal_id', $this->proposal->id)->where('user_id', $this->memberOne->id)->update(['user_id' => null]);
        $this->approveOverHttp();
        $proceeding = $this->proceeding->fresh();
        $members = $this->service()->documents($proceeding)->first()->data['members'];

        $this->assertSame([$this->memberOne->id, 'Member One', true, 'सदस्य, छत्तीसगढ़ लोक सेवा आयोग'], [$members[0]['user_id'], $members[0]['name'], $members[0]['chairman'], $members[0]['designation']]);
        $this->assertSame(1, count(array_filter($members, fn (array $m): bool => $m['chairman'])), 'One chairman.');
        $this->assertNull($this->service()->signRefusal($proceeding, $this->memberOne));
    }

    /** @return TestResponse<Response> */
    private function signThroughTheSimulator(User $user, DpcProposal $proposal): TestResponse
    {
        $user->forceFill(['must_change_password' => false, 'email_verified_at' => now()])->save();
        $start = $this->actingAs($user)->from(route('sitting.show', $proposal))->post(route('result-documents.sign', $this->proceeding));
        if (session('esign.pending') === null) {
            return $start;
        }

        return $this->post(route('esign.simulator.submit'), ['reference' => (string) session('esign.pending')['reference'], 'decision' => 'sign', 'name' => $user->name]);
    }
}
