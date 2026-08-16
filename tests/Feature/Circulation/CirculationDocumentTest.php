<?php

declare(strict_types=1);

namespace Tests\Feature\Circulation;

use App\Domain\Circulation\Documents\CirculationDocumentRenderer;
use App\Domain\Circulation\Documents\HtmlCirculationDocumentRenderer;
use App\Domain\Circulation\Enums\CirculationDecision;
use App\Domain\Circulation\Enums\CirculationDocumentStatus;
use App\Domain\Circulation\Enums\CirculationDocumentType;
use App\Domain\Circulation\Exceptions\PendingBusinessDecisionException;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Circulation\Models\DpcCirculationDocument;
use App\Domain\Circulation\Services\CirculationCommissionService;
use App\Domain\Circulation\Services\CirculationDocumentService;
use App\Domain\Circulation\Services\CirculationService;
use App\Domain\Circulation\Services\CirculationWorkflowService;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Proceeding\Documents\DocumentRenderer;
use App\Domain\Proceeding\Enums\DocumentType;
use App\Domain\Proceeding\Models\DpcProceedingDocument;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\Circulation\Concerns\BuildsCirculations;
use Tests\TestCase;

/**
 * The prescribed form — render → store → SHA-256 → sign → verify → publish
 * (PPT S45, S48; C3/C7) — M9 Phase 3.
 *
 * Two things are tested harder than the rest, because they are what the
 * architect rulings turn on:
 *
 * - **§23.1** — while BD-14 is unresolved, publication is refused with the
 *   exact words *"Pending Business Decision (BD-14)"*, no effective date is
 *   invented, and **rendering, signing, storage and verification still work
 *   completely**. A publication guard that also disabled generation would meet
 *   the letter of "refuse" and defeat the whole ruling.
 * - **AQ-22** — M9's own document type, table and renderer. M7's are neither
 *   used nor touched.
 */
class CirculationDocumentTest extends TestCase
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
        $circulation = $workflow->approve(
            $workflow->submit($raised, $this->authorizedOfficer, request()),
            $this->administrativeHead,
            request(),
        );

        $commission = app(CirculationCommissionService::class);

        foreach ($circulation->items as $item) {
            $commission->decide($circulation, $item, $this->memberOne, CirculationDecision::Consented);
        }

        $this->circulation = $commission->sign(
            $commission->consent($circulation->fresh(), $this->memberOne, request()),
            $this->memberOne,
            request(),
        );
    }

    private function documents(): CirculationDocumentService
    {
        return app(CirculationDocumentService::class);
    }

    // ------------------------------------------------- generation & pipeline

    public function test_both_documents_are_generated_stored_hashed_and_signed(): void
    {
        $generated = $this->documents()->generate($this->circulation, $this->memberOne);

        $this->assertCount(2, $generated);
        $this->assertEqualsCanonicalizing(
            ['circulation_note', 'revised_promotion_list'],
            $generated->map(fn (DpcCirculationDocument $d): string => $d->type->value)->all(),
        );

        foreach ($generated as $document) {
            $this->assertSame(CirculationDocumentStatus::Generated, $document->status);
            $this->assertNotNull($document->path);
            $this->assertNotNull($document->signature_id, 'Every document is signed.');
            $this->assertSame(64, strlen((string) $document->content_hash));
            $this->assertNotNull($document->generated_at);
            Storage::disk((string) $document->disk)->assertExists((string) $document->path);
        }
    }

    public function test_the_documents_carry_their_permanent_references(): void
    {
        $document = $this->documents()->generate($this->circulation, $this->memberOne)->first();

        $this->assertSame($this->circulation->uuid, $document->circulation_uuid);
        $this->assertSame((string) $this->proposal->uuid, $document->origin_proposal_uuid);
        $this->assertSame('HE202600001', $document->origin_dpc_code);
        $this->assertNotNull($document->generator_version);
    }

    public function test_a_generated_document_verifies_against_its_stored_bytes(): void
    {
        $document = $this->documents()->generate($this->circulation, $this->memberOne)->first();

        $this->assertTrue($this->documents()->verify($document));
    }

    public function test_verification_fails_if_the_stored_file_is_tampered_with(): void
    {
        $document = $this->documents()->generate($this->circulation, $this->memberOne)->first();

        Storage::disk((string) $document->disk)->put((string) $document->path, 'tampered');

        // The hash is not decoration: it is how a department's copy is shown
        // to be the copy the Commission issued.
        $this->assertFalse($this->documents()->verify($document));
    }

    public function test_a_generated_document_is_immutable(): void
    {
        $document = $this->documents()->generate($this->circulation, $this->memberOne)->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $document->update(['content_hash' => str_repeat('0', 64)]);
    }

    public function test_a_generated_document_cannot_be_deleted(): void
    {
        $document = $this->documents()->generate($this->circulation, $this->memberOne)->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be deleted');

        $document->delete();
    }

    public function test_regenerating_creates_a_new_version_and_never_overwrites(): void
    {
        $this->documents()->generate($this->circulation, $this->memberOne);
        $this->documents()->generate($this->circulation, $this->memberOne);

        $history = $this->documents()->allVersions($this->circulation);
        $current = $this->documents()->documents($this->circulation);

        $this->assertCount(4, $history, 'Both versions of both documents are kept.');
        $this->assertCount(2, $current, 'Only the newest version of each is current.');
        $this->assertSame([2, 2], $current->pluck('document_version')->sort()->values()->all());
    }

    public function test_documents_cannot_be_produced_before_the_note_is_signed(): void
    {
        $unsigned = app(CirculationService::class)->raise(
            $this->proposal->fresh(),
            $this->authorizedOfficer,
            Carbon::parse('2026-07-26'),
            $this->itemsFor($this->crHoldProxy()),
        );

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('has not been signed');

        $this->documents()->generate($unsigned, $this->memberOne);
    }

    // -------------------------------------------------------------- contents

    public function test_the_note_states_what_it_resolves_and_what_it_does_not_do(): void
    {
        $note = $this->documents()->generate($this->circulation, $this->memberOne)
            ->firstWhere('type', CirculationDocumentType::CirculationNote);

        $html = $this->documents()->contents($note);

        $this->assertStringContainsString('परिभ्रमण टीप', $html);
        $this->assertStringContainsString('HE202600001', $html);
        $this->assertStringContainsString('US8', $html);
        $this->assertStringContainsString('Immovable property return not recorded', $html);
        // AD-23, stated on the face of the document.
        $this->assertStringContainsString('It does not', $html);
        $this->assertStringContainsString('re-run the DPC', $html);
    }

    public function test_the_revised_list_is_the_stored_result_with_cir_cleared(): void
    {
        $list = $this->documents()->generate($this->circulation, $this->memberOne)
            ->firstWhere('type', CirculationDocumentType::RevisedPromotionList);

        $html = $this->documents()->contents($list);

        $this->assertStringContainsString('REVISED PROMOTION LIST', $html);
        $this->assertStringContainsString('nothing has been recalculated', $html);
        // US9 is still held on the CR ground and is not on this note, so their
        // CIR must remain; US8's is cleared.
        $this->assertStringContainsString('US9', $html);
        $this->assertStringContainsString('CIR', $html);
    }

    public function test_generating_documents_recalculates_nothing(): void
    {
        $run = DpcCalculationRun::query()->where('dpc_proposal_id', $this->proposal->id)->latest('id')->firstOrFail();
        $runs = DpcCalculationRun::query()->count();
        $before = $run->fresh()->toArray();

        $this->documents()->generate($this->circulation, $this->memberOne);

        $this->assertSame($runs, DpcCalculationRun::query()->count());
        $this->assertSame($before, $run->fresh()->toArray());
    }

    // ---------------------------------------------------- §23.1 — BD-14 gate

    public function test_publication_is_refused_while_bd14_is_unresolved(): void
    {
        $this->documents()->generate($this->circulation, $this->memberOne);

        try {
            $this->documents()->publish($this->circulation, $this->memberOne);
            $this->fail('Publication must be refused while BD-14 is open.');
        } catch (PendingBusinessDecisionException $e) {
            $payload = $e->decision->toArray();

            $this->assertSame('BD-14', $payload['code']);
            // The ruling requires this string exactly.
            $this->assertSame('Pending Business Decision (BD-14)', $payload['title']);
            $this->assertNotEmpty($payload['not_done']);
        }
    }

    public function test_the_refusal_displays_the_exact_required_words(): void
    {
        $this->documents()->generate($this->circulation, $this->memberOne);

        $response = $this->actingAs($this->memberOne)
            ->post(route('cgpsc.circulations.documents.publish', $this->circulation));

        $response->assertRedirect();
        $response->assertSessionHas('pendingBusinessDecision');
        $response->assertSessionHasNoErrors();

        $this->assertSame('Pending Business Decision (BD-14)', session('pendingBusinessDecision')['title']);

        // And it reaches the screen, not just the session — a refusal nobody
        // can read is indistinguishable from a fault.
        $this->actingAs($this->memberOne)
            ->get(route('cgpsc.circulations.documents.index', $this->circulation))
            ->assertOk()
            ->assertSee('Pending Business Decision (BD-14)');
    }

    public function test_nothing_is_published_while_bd14_is_open(): void
    {
        $this->documents()->generate($this->circulation, $this->memberOne);

        try {
            $this->documents()->publish($this->circulation, $this->memberOne);
        } catch (PendingBusinessDecisionException) {
            // expected
        }

        $this->assertSame(
            0,
            DpcCirculationDocument::query()->whereNotNull('published_at')->count(),
        );
    }

    public function test_rendering_signing_storage_and_verification_all_work_while_bd14_is_open(): void
    {
        // The load-bearing test for §23.1. A guard that also disabled
        // generation would satisfy "publication is refused" and defeat the
        // ruling entirely.
        $generated = $this->documents()->generate($this->circulation, $this->memberOne);

        foreach ($generated as $document) {
            $this->assertSame(CirculationDocumentStatus::Generated, $document->status);
            $this->assertNotNull($document->signature_id);
            $this->assertNotEmpty($this->documents()->contents($document));
            $this->assertTrue($this->documents()->verify($document));
            $this->assertFalse($document->isPublished());
        }
    }

    public function test_no_effective_date_is_invented(): void
    {
        $note = $this->documents()->generate($this->circulation, $this->memberOne)
            ->firstWhere('type', CirculationDocumentType::CirculationNote);

        $html = $this->documents()->contents($note);

        // Both facts are stated…
        $this->assertStringContainsString('Promotion as on', $html);
        $this->assertStringContainsString('Reservation released on', $html);
        // …and neither is asserted as governing.
        $this->assertStringContainsString('asserts neither as the effective date', $html);
        $this->assertStringNotContainsString('Effective date basis:', $html);
    }

    public function test_the_refusal_is_recorded_on_the_timeline(): void
    {
        $this->documents()->generate($this->circulation, $this->memberOne);

        try {
            $this->documents()->publish($this->circulation, $this->memberOne);
        } catch (PendingBusinessDecisionException) {
            // expected
        }

        $entry = ActivityTimeline::query()
            ->where('event', 'dpc_circulation.publication_pending_business_decision')
            ->firstOrFail();

        $this->assertSame($this->proposal->id, $entry->subject_id);
        $this->assertSame('BD-14', $entry->properties['code']);
    }

    public function test_publication_succeeds_once_bd14_is_answered(): void
    {
        // BD-14 is expressed as configuration (AD-3), so CGPSC's ruling is
        // applied by setting a value — no code change.
        config(['dpc.circulation.effective_date_basis' => 'origin_dpc_year']);

        $this->documents()->generate($this->circulation, $this->memberOne);
        $this->documents()->publish($this->circulation, $this->memberOne);

        $this->assertSame(2, DpcCirculationDocument::query()->whereNotNull('published_at')->count());

        $entry = ActivityTimeline::query()
            ->where('event', 'dpc_circulation.documents_published')->firstOrFail();
        $this->assertSame($this->proposal->id, $entry->subject_id);
    }

    public function test_once_answered_the_note_states_the_basis(): void
    {
        config(['dpc.circulation.effective_date_basis' => 'origin_dpc_year']);

        $note = $this->documents()->generate($this->circulation, $this->memberOne)
            ->firstWhere('type', CirculationDocumentType::CirculationNote);

        $html = $this->documents()->contents($note);

        $this->assertStringContainsString('Effective date basis:', $html);
        $this->assertStringNotContainsString('Pending Business Decision (BD-14)', $html);
    }

    // ------------------------------------------------------- download & API

    public function test_a_document_can_be_downloaded_and_verified_over_http(): void
    {
        $document = $this->documents()->generate($this->circulation, $this->memberOne)->first();

        $this->actingAs($this->memberOne)
            ->get(route('cgpsc.circulations.documents.download', [$this->circulation, $document]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8');

        $this->actingAs($this->memberOne)
            ->postJson(route('cgpsc.circulations.documents.verify', [$this->circulation, $document]))
            ->assertOk()
            ->assertJsonPath('verified', true);
    }

    public function test_the_api_refuses_publication_with_a_structured_409(): void
    {
        $this->documents()->generate($this->circulation, $this->memberOne);

        $this->actingAs($this->memberOne)
            ->postJson(route('cgpsc.circulations.documents.publish', $this->circulation))
            ->assertStatus(409)
            ->assertJsonPath('code', 'BD-14')
            ->assertJsonPath('title', 'Pending Business Decision (BD-14)');
    }

    public function test_a_document_of_another_circulation_is_not_reachable(): void
    {
        $document = $this->documents()->generate($this->circulation, $this->memberOne)->first();

        $other = app(CirculationService::class)->raise(
            $this->proposal->fresh(),
            $this->authorizedOfficer,
            Carbon::parse('2026-07-27'),
            $this->itemsFor($this->crHoldProxy()),
        );

        $this->actingAs($this->memberOne)
            ->get(route('cgpsc.circulations.documents.download', [$other, $document]))
            ->assertNotFound();
    }

    public function test_the_document_history_screen_renders(): void
    {
        $this->documents()->generate($this->circulation, $this->memberOne);

        $this->actingAs($this->memberOne)
            ->get(route('cgpsc.circulations.documents.index', $this->circulation))
            ->assertOk()
            ->assertSee('Document history')
            ->assertSee('Circulation Note');
    }

    // --------------------------------------------------------- AQ-22 / AD-21

    public function test_m9_uses_its_own_document_type_and_never_m7s(): void
    {
        $this->assertEqualsCanonicalizing(
            ['circulation_note', 'revised_promotion_list'],
            array_map(fn (CirculationDocumentType $t): string => $t->value, CirculationDocumentType::cases()),
        );

        // AQ-22: M7's enum is untouched and gained no circulation case.
        $m7 = array_map(
            fn (DocumentType $t): string => $t->value,
            DocumentType::cases(),
        );
        $this->assertEqualsCanonicalizing(['proceedings', 'promotion_list'], $m7);
    }

    public function test_m9_documents_do_not_touch_m7s_document_table(): void
    {
        $before = DpcProceedingDocument::query()->count();

        $this->documents()->generate($this->circulation, $this->memberOne);

        $this->assertSame($before, DpcProceedingDocument::query()->count());
        $this->assertSame(2, DpcCirculationDocument::query()->count());
    }

    public function test_m9_binds_its_own_renderer_and_not_m7s(): void
    {
        $renderer = app(CirculationDocumentRenderer::class);

        $this->assertInstanceOf(
            HtmlCirculationDocumentRenderer::class,
            $renderer,
        );
        $this->assertNotInstanceOf(DocumentRenderer::class, $renderer);
    }

    /**
     * A second usable hold, so tests needing another circulation have one the
     * department may actually raise (a CR ground is refused by BD-15).
     */
    private function crHoldProxy(): DpcProceedingHold
    {
        return DpcProceedingHold::query()->create([
            'dpc_proceeding_id' => $this->proceeding->id,
            'sn' => 21,
            'officer_name' => 'US21',
            'reason_code' => 'property_missing',
            'detail' => 'Not recorded for 2014',
            'is_automatic' => false,
            'recorded_by_name' => 'Member One',
            'recorded_at' => now(),
        ]);
    }
}
