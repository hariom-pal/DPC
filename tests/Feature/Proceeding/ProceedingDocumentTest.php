<?php

declare(strict_types=1);

namespace Tests\Feature\Proceeding;

use App\Domain\Proceeding\Documents\DocumentRenderer;
use App\Domain\Proceeding\Documents\HtmlDocumentRenderer;
use App\Domain\Proceeding\Enums\CorrectableField;
use App\Domain\Proceeding\Enums\DocumentStatus;
use App\Domain\Proceeding\Enums\DocumentType;
use App\Domain\Proceeding\Events\ProceedingDocumentsPublished;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingDocument;
use App\Domain\Proceeding\Services\ProceedingDocumentService;
use App\Domain\Proceeding\Services\ResultApprovalService;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Enums\Feature;
use Illuminate\Support\Facades\Event;
use OwenIt\Auditing\Models\Audit;
use RuntimeException;
use Tests\Feature\Proceeding\Concerns\BuildsProceedings;
use Tests\TestCase;

/**
 * Proceedings document and promotion list (PPT S33–S34) — M7 Phase 4.
 *
 * The pipeline is **render → store → sign → publish**, and each step is tested
 * as a separate obligation. The one that matters most is the last: a
 * provisional result must never reach a department.
 */
class ProceedingDocumentTest extends TestCase
{
    use BuildsProceedings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProceeding();

        $this->officer(1, 'US1', $this->urCategoryId(),
            [2013 => 'क', 2014 => 'क', 2015 => 'क', 2016 => 'क', 2017 => 'क']);
    }

    private function documents(): ProceedingDocumentService
    {
        return app(ProceedingDocumentService::class);
    }

    private function approve(): void
    {
        $this->calculate();
        app(ResultApprovalService::class)->approve(
            $this->proceeding->fresh(), $this->memberOne, request(), 2017,
        );
    }

    private function generate()
    {
        return $this->actingAs($this->memberOne)
            ->post(route('cgpsc.proceedings.documents.store', $this->proceeding));
    }

    private function publish()
    {
        return $this->actingAs($this->memberOne)
            ->post(route('cgpsc.proceedings.documents.publish', $this->proceeding));
    }

    // ------------------------------------------------------------ generation

    public function test_both_documents_are_generated_after_approval(): void
    {
        $this->approve();

        $this->generate()->assertSessionHasNoErrors();

        $this->assertSame(2, DpcProceedingDocument::query()->count());

        foreach (DocumentType::cases() as $type) {
            $document = DpcProceedingDocument::query()->where('type', $type->value)->firstOrFail();
            $this->assertSame(DocumentStatus::Generated, $document->status);
            $this->assertNotNull($document->path);
            $this->assertNotNull($document->generated_at);
        }
    }

    public function test_documents_cannot_be_generated_before_approval(): void
    {
        $this->calculate();

        $this->generate()->assertSessionHasErrors('document');

        $this->assertSame(0, DpcProceedingDocument::query()->count());
    }

    public function test_a_document_carries_uuid_version_and_generator_version(): void
    {
        // Architect Directive 3 (Phase 1).
        $this->approve();
        $this->generate();

        $document = DpcProceedingDocument::query()->firstOrFail();

        $this->assertNotEmpty($document->uuid);
        $this->assertSame(1, $document->document_version);
        $this->assertSame('html-v1', $document->generator_version);
        $this->assertNotNull($document->generated_at);
    }

    public function test_a_document_permanently_references_the_three_uuids(): void
    {
        // Architect Directive 2 (Phase 4).
        $this->approve();
        $this->generate();

        $proceeding = $this->proceeding->fresh();
        $document = DpcProceedingDocument::query()->firstOrFail();

        $this->assertSame($proceeding->uuid, $document->proceeding_uuid);
        $this->assertSame($proceeding->finalCalculationRun->uuid, $document->final_calculation_uuid);
        $this->assertSame($proceeding->proposal->uuid, $document->proposal_uuid);
        $this->assertNotNull($document->proposal_uuid);
    }

    public function test_a_document_records_the_calculation_engine_version(): void
    {
        // Architect Directive 1 (Phase 4).
        $this->approve();
        $this->generate();

        $this->assertSame(
            (string) config('dpc.scoring.engine_version'),
            DpcProceedingDocument::query()->firstOrFail()->calculation_engine_version,
        );
    }

    public function test_a_document_records_the_generator_commit_hash(): void
    {
        // Architect Directive 4. `generator_version` is a label somebody has
        // to remember to bump; the commit hash is a fact, and it is what lets
        // the exact renderer be checked out and re-run years later.
        config()->set('dpc.application.git_commit', 'a1b2c3d4e5f6');

        $this->approve();
        $this->generate();

        $document = DpcProceedingDocument::query()->firstOrFail();

        $this->assertSame('a1b2c3d4e5f6', $document->generator_commit_hash);
        $this->assertStringContainsString('a1b2c3d4e5f6', $this->documents()->contents($document));
    }

    public function test_the_commit_hash_is_recorded_even_when_the_build_does_not_know_it(): void
    {
        // A development checkout has no APP_GIT_COMMIT. Recording `unknown`
        // honestly beats refusing to produce the document.
        config()->set('dpc.application.git_commit', 'unknown');

        $this->approve();
        $this->generate();

        $this->assertSame('unknown', DpcProceedingDocument::query()->firstOrFail()->generator_commit_hash);
    }

    public function test_a_document_is_hashed_and_verifiable(): void
    {
        $this->approve();
        $this->generate();

        $document = DpcProceedingDocument::query()->firstOrFail();

        $this->assertSame(64, strlen((string) $document->content_hash));
        $this->assertTrue($this->documents()->verify($document));
    }

    public function test_a_document_is_signed_through_the_signature_abstraction(): void
    {
        // The CSC DigiSign *API* is not implemented; the integration point is.
        $this->approve();
        $this->generate();

        $document = DpcProceedingDocument::query()->firstOrFail();

        $this->assertNotNull($document->signature_id);
        $this->assertNotNull($document->signature);
    }

    public function test_re_issuing_creates_a_new_version_and_keeps_the_old_one(): void
    {
        $this->approve();
        $this->generate();
        $first = DpcProceedingDocument::query()->where('type', DocumentType::PromotionList->value)->firstOrFail();

        $this->generate();

        $versions = DpcProceedingDocument::query()
            ->where('type', DocumentType::PromotionList->value)->orderBy('document_version')->get();

        $this->assertCount(2, $versions);
        $this->assertSame(1, $versions[0]->document_version);
        $this->assertSame(2, $versions[1]->document_version);
        // The artefact a department was already given must not change.
        $this->assertSame($first->content_hash, $versions[0]->fresh()->content_hash);
    }

    public function test_a_generated_document_cannot_be_rewritten(): void
    {
        $this->approve();
        $this->generate();
        $document = DpcProceedingDocument::query()->firstOrFail();

        $this->expectException(RuntimeException::class);
        $document->update(['content_hash' => str_repeat('f', 64)]);
    }

    public function test_a_document_cannot_be_deleted(): void
    {
        $this->approve();
        $this->generate();

        $this->expectException(RuntimeException::class);
        DpcProceedingDocument::query()->firstOrFail()->delete();
    }

    public function test_a_generation_failure_is_recorded_rather_than_swallowed(): void
    {
        // The decision has already been taken and stands; what is missing is
        // its rendering, and the row must say so.
        $this->approve();

        $this->app->bind(DocumentRenderer::class, function () {
            return new class implements DocumentRenderer
            {
                public function version(): string
                {
                    return 'broken-v1';
                }

                public function contentType(): string
                {
                    return 'text/html';
                }

                public function extension(): string
                {
                    return 'html';
                }

                public function render(DpcProceeding $proceeding, DocumentType $type): string
                {
                    throw new RuntimeException('Template exploded.');
                }
            };
        });

        $this->generate();

        $document = DpcProceedingDocument::query()->firstOrFail();
        $this->assertSame(DocumentStatus::Failed, $document->status);
        $this->assertStringContainsString('Template exploded', (string) $document->failure_reason);

        $this->assertSame(
            1,
            ActivityTimeline::query()->where('event', 'dpc_proceeding.document_failed')->count() > 0 ? 1 : 0,
        );
    }

    // -------------------------------------------------------------- contents

    public function test_the_promotion_list_renders_the_main_and_waiting_lists(): void
    {
        $this->approve();
        $this->generate();

        $document = DpcProceedingDocument::query()
            ->where('type', DocumentType::PromotionList->value)->firstOrFail();
        $html = $this->documents()->contents($document);

        $this->assertStringContainsString('PROMOTION AS ON 2017', $html);
        $this->assertStringContainsString('US1', $html);
        $this->assertStringContainsString('WAITING LIST', $html);
    }

    public function test_the_proceedings_document_records_corrections_old_beside_new(): void
    {
        $this->corrections()->record(
            $this->proceeding, $this->memberOne, CorrectableField::CrGrade, 1, 2013, 'क+',
            'CR for 2013 was transcribed incorrectly by the department.',
        );
        $this->approve();
        $this->generate();

        $document = DpcProceedingDocument::query()
            ->where('type', DocumentType::Proceedings->value)->firstOrFail();
        $html = $this->documents()->contents($document);

        $this->assertStringContainsString('Corrections Recorded by the Commission', $html);
        // The department's entry, struck through beside the corrected value.
        $this->assertStringContainsString('<del>क</del>', $html);
        $this->assertStringContainsString('transcribed incorrectly', $html);
    }

    public function test_the_documents_carry_the_three_permanent_references(): void
    {
        $this->approve();
        $this->generate();

        $proceeding = $this->proceeding->fresh();
        $html = $this->documents()->contents(DpcProceedingDocument::query()->firstOrFail());

        $this->assertStringContainsString($proceeding->uuid, $html);
        $this->assertStringContainsString((string) $proceeding->proposal->uuid, $html);
        $this->assertStringContainsString($proceeding->finalCalculationRun->uuid, $html);
    }

    public function test_a_held_promotion_appears_on_the_promotion_list(): void
    {
        $this->officer(8, 'US8', $this->stCategoryId(),
            [2013 => 'क+', 2014 => 'क+', 2015 => 'क+', 2016 => 'क+', 2017 => 'क+'],
            propertyMissingYears: [2016],
        );
        $this->approve();
        $this->generate();

        $html = $this->documents()->contents(
            DpcProceedingDocument::query()->where('type', DocumentType::PromotionList->value)->firstOrFail()
        );

        $this->assertStringContainsString('CIR', $html);
        $this->assertStringContainsString('Promotions Held', $html);
    }

    // ------------------------------------------------------------ publication

    public function test_documents_can_be_published_to_the_department(): void
    {
        $this->approve();
        $this->generate();

        $this->publish()->assertSessionHasNoErrors();

        $this->assertSame(0, DpcProceedingDocument::query()->whereNull('published_at')->count());
    }

    public function test_a_provisional_result_cannot_be_published(): void
    {
        // Architect Directive 5. The most important rule in this phase: a
        // promotion list carrying an unsettled rule must never reach a
        // department, where it would be read as final.
        $this->approve();
        $this->generate();

        $run = $this->proceeding->fresh()->finalCalculationRun;
        $run->forceFill(['has_pending_decisions' => true])->saveQuietly();

        $this->publish()->assertSessionHasErrors('document');

        $this->assertSame(2, DpcProceedingDocument::query()->whereNull('published_at')->count());
    }

    public function test_nothing_can_be_published_before_it_is_generated(): void
    {
        $this->approve();

        $this->publish()->assertSessionHasErrors('document');
    }

    public function test_publication_notifies_the_department(): void
    {
        Event::fake([ProceedingDocumentsPublished::class]);

        $this->approve();
        $this->generate();
        $this->publish();

        Event::assertDispatched(ProceedingDocumentsPublished::class, function (ProceedingDocumentsPublished $event): bool {
            return $event->proceedingUuid === $this->proceeding->fresh()->uuid
                && $event->departmentId === $this->department->id
                && count($event->documentUuids) === 2;
        });
    }

    public function test_publication_records_a_timeline_event(): void
    {
        $this->approve();
        $this->generate();
        $this->publish();

        $entry = ActivityTimeline::query()
            ->where('event', 'dpc_proceeding.documents_published')->firstOrFail();

        $this->assertSame($this->proceeding->fresh()->uuid, $entry->properties['proceeding_uuid']);
        $this->assertCount(2, $entry->properties['document_uuids']);
    }

    // ------------------------------------------------------------- access

    public function test_a_document_is_streamed_through_a_policy_check(): void
    {
        $this->approve();
        $this->generate();
        $document = DpcProceedingDocument::query()->firstOrFail();

        $this->actingAs($this->memberOne)
            ->get(route('cgpsc.proceedings.documents.show', [$this->proceeding, $document]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function test_another_member_cannot_read_a_document(): void
    {
        $this->approve();
        $this->generate();
        $document = DpcProceedingDocument::query()->firstOrFail();

        $this->actingAs($this->memberTwo)
            ->get(route('cgpsc.proceedings.documents.show', [$this->proceeding, $document]))
            ->assertForbidden();
    }

    public function test_another_member_cannot_generate_documents(): void
    {
        $this->approve();

        $this->actingAs($this->memberTwo)
            ->post(route('cgpsc.proceedings.documents.store', $this->proceeding))
            ->assertForbidden();
    }

    public function test_a_document_is_addressed_by_uuid(): void
    {
        $this->approve();
        $this->generate();
        $document = DpcProceedingDocument::query()->firstOrFail();

        $url = route('cgpsc.proceedings.documents.show', [$this->proceeding, $document]);

        $this->assertStringContainsString($document->uuid, $url);
        $this->assertStringNotContainsString('/documents/'.$document->id, $url);
    }

    public function test_a_document_is_audited_and_tagged_with_the_proceeding_uuid(): void
    {
        $this->approve();
        $this->generate();

        $audit = Audit::query()
            ->where('auditable_type', (new DpcProceedingDocument)->getMorphClass())->firstOrFail();

        $this->assertStringContainsString($this->proceeding->fresh()->uuid, (string) $audit->tags);
    }

    // --------------------------------------------------------- abstractions

    public function test_the_renderer_is_resolved_from_the_contract(): void
    {
        // AQ-3: PDF later replaces HTML by binding a different implementation,
        // with nothing in the approval path changing.
        $this->assertInstanceOf(HtmlDocumentRenderer::class, app(DocumentRenderer::class));
        $this->assertSame('html', app(DocumentRenderer::class)->extension());
    }

    public function test_the_document_service_does_not_depend_on_approval(): void
    {
        // Architect Directive 3. Approval creates the decision; documents
        // render it. A constructor dependency the other way would let a
        // rendering concern reach into a legal one.
        $constructor = (new \ReflectionClass(ProceedingDocumentService::class))->getConstructor();

        $types = array_map(
            fn (\ReflectionParameter $p): string => (string) $p->getType(),
            $constructor->getParameters(),
        );

        $this->assertNotContains(ResultApprovalService::class, $types);
    }

    public function test_documents_are_stored_through_the_storage_abstraction(): void
    {
        // FEATURE_S3_STORAGE decides local or S3; nothing in M7 knows which.
        $this->assertTrue(Feature::S3Storage->disabled());

        $this->approve();
        $this->generate();

        $this->assertSame(
            (string) config('dpc.proceeding.documents.disk'),
            DpcProceedingDocument::query()->firstOrFail()->disk,
        );
    }
}
