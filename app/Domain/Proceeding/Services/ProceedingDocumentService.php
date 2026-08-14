<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Proceeding\Documents\DocumentRenderer;
use App\Domain\Proceeding\Enums\DocumentStatus;
use App\Domain\Proceeding\Enums\DocumentType;
use App\Domain\Proceeding\Events\ProceedingDocumentsPublished;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingDocument;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Services\SignatureService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Proceedings document and promotion list (PPT S33–S34) — M7 Phase 4.
 *
 * **Independent of approval** (architect Directive 3). Approval creates the
 * legal decision; this renders it. Nothing here can approve, and
 * `ResultApprovalService` knows nothing about documents — so a rendering
 * failure can never damage a decision that has already been taken, and a
 * template change can never require re-approval.
 *
 * The pipeline is **render → store → sign → publish**, in that order and with
 * that separation:
 *
 * - **render** through the `DocumentRenderer` contract, so PDF later replaces
 *   HTML without touching this service (AQ-3);
 * - **store** through the storage abstraction, so `FEATURE_S3_STORAGE` decides
 *   local or S3 and nothing here knows which;
 * - **sign** through `SignatureService`, which resolves the provider by flag —
 *   the integration point for CSC DigiSign, whose API is *not* implemented;
 * - **publish** separately, because a **provisional result must never be
 *   published** (Directive 5).
 */
class ProceedingDocumentService
{
    public function __construct(
        private readonly DocumentRenderer $renderer,
        private readonly SignatureService $signatures,
        private readonly ActivityTimelineService $timeline,
    ) {}

    /**
     * Generate both documents for an approved sitting.
     *
     * @return EloquentCollection<int, DpcProceedingDocument>
     */
    public function generate(DpcProceeding $proceeding, User $actor): EloquentCollection
    {
        $this->assertApproved($proceeding);

        $documents = new EloquentCollection;

        foreach (DocumentType::cases() as $type) {
            $documents->push($this->generateOne($proceeding, $actor, $type));
        }

        return $documents;
    }

    /**
     * Release the documents to the department.
     *
     * **Refused while the result is provisional** (Directive 5). A publication
     * that quietly went ahead would put a promotion list carrying an unsettled
     * rule into a department's hands, where it would be read as final —
     * exactly the outcome the provisional flag exists to prevent.
     */
    public function publish(DpcProceeding $proceeding, User $actor): void
    {
        $this->assertApproved($proceeding);

        $run = $proceeding->finalCalculationRun;

        if ($run !== null && $run->has_pending_decisions) {
            throw new WorkflowException(
                'This result is provisional: a business rule affecting it has not been settled. '
                .'It cannot be published until that rule is confirmed.'
            );
        }

        $documents = $this->documents($proceeding);

        if ($documents->isEmpty() || $documents->contains(fn (DpcProceedingDocument $d): bool => ! $d->isAvailable())) {
            throw new WorkflowException(
                'The documents for this DPC have not all been generated yet, so nothing can be published.'
            );
        }

        DB::transaction(function () use ($proceeding, $actor, $documents): void {
            foreach ($documents as $document) {
                if (! $document->isPublished()) {
                    $document->update(['published_at' => now()]);
                }
            }

            ProceedingDocumentsPublished::dispatch(
                $proceeding->uuid,
                (string) $proceeding->proposal->uuid,
                $proceeding->finalCalculationRun?->uuid,
                $documents->pluck('uuid')->all(),
                $proceeding->proposal->department_id,
                $proceeding->proposal->dpc_code,
            );

            $this->timeline->record(
                'dpc_proceeding.documents_published',
                $proceeding->proposal,
                'Result documents published to the department',
                [
                    'proceeding_uuid' => $proceeding->uuid,
                    'document_uuids' => $documents->pluck('uuid')->all(),
                    'published_at' => now()->toIso8601String(),
                ],
                $actor,
            );
        });
    }

    /**
     * The current version of each document, newest first.
     *
     * @return EloquentCollection<int, DpcProceedingDocument>
     */
    public function documents(DpcProceeding $proceeding): EloquentCollection
    {
        return DpcProceedingDocument::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->orderByDesc('document_version')
            ->get()
            ->unique('type')
            ->values();
    }

    /**
     * Every document ever generated, including superseded versions.
     *
     * @return EloquentCollection<int, DpcProceedingDocument>
     */
    public function allVersions(DpcProceeding $proceeding): EloquentCollection
    {
        return DpcProceedingDocument::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->orderBy('type')
            ->orderByDesc('document_version')
            ->get();
    }

    public function contents(DpcProceedingDocument $document): string
    {
        if (! $document->isAvailable()) {
            throw new WorkflowException('That document has not been generated.');
        }

        return (string) Storage::disk((string) $document->disk)->get((string) $document->path);
    }

    /**
     * Whether the stored file still matches the hash taken when it was
     * generated. The hash is not decoration: it is how a department's copy is
     * shown to be the copy the Commission issued.
     */
    public function verify(DpcProceedingDocument $document): bool
    {
        return hash('sha256', $this->contents($document)) === $document->content_hash;
    }

    // ------------------------------------------------------------- internals

    private function generateOne(DpcProceeding $proceeding, User $actor, DocumentType $type): DpcProceedingDocument
    {
        $version = $this->nextVersion($proceeding, $type);

        // The row exists before the file does. A generation that never
        // completes then leaves visible evidence of an expected-but-absent
        // legal document rather than silence.
        $document = DpcProceedingDocument::query()->create([
            'dpc_proceeding_id' => $proceeding->id,
            'type' => $type->value,
            'status' => DocumentStatus::Pending->value,
            // Directive 2 — the three permanent references.
            'proceeding_uuid' => $proceeding->uuid,
            'final_calculation_uuid' => $proceeding->finalCalculationRun?->uuid,
            'proposal_uuid' => (string) $proceeding->proposal->uuid,
            'document_version' => $version,
            'generator_version' => $this->renderer->version(),
            // Directive 4. `generator_version` is a label somebody has to
            // remember to bump; the commit hash is a fact. For a document
            // produced in evidence years later, the second is what lets the
            // exact renderer be checked out and re-run.
            'generator_commit_hash' => (string) config('dpc.application.git_commit'),
            'calculation_engine_version' => $proceeding->calculation_engine_version,
            'rule_version' => $proceeding->finalCalculationRun?->rule_version,
            'generated_by' => $actor->id,
            'generated_by_name' => $actor->name,
        ]);

        try {
            $contents = $this->renderer->render($proceeding, $type);

            $disk = (string) config('dpc.proceeding.documents.disk', 'local');
            $path = 'proceedings/'.$proceeding->uuid.'/'.$document->uuid.'-'
                .$type->value.'.'.$this->renderer->extension();

            Storage::disk($disk)->put($path, $contents);

            $signature = $this->signatures->sign(
                $actor,
                $document,
                $contents,
                $type->filename(),
                [
                    'proceeding_uuid' => $proceeding->uuid,
                    'proposal_uuid' => (string) $proceeding->proposal->uuid,
                    'final_calculation_uuid' => $proceeding->finalCalculationRun?->uuid,
                    'document_version' => $version,
                ],
            );

            $document->update([
                'status' => DocumentStatus::Generated->value,
                'disk' => $disk,
                'path' => $path,
                'content_hash' => hash('sha256', $contents),
                'signature_id' => $signature->id,
                'generated_at' => now(),
            ]);

            $this->timeline->record(
                'dpc_proceeding.document_generated',
                $proceeding->proposal,
                $type->label().' generated',
                array_merge($this->eventProperties($document->fresh()), [
                    'content_hash' => $document->fresh()->content_hash,
                ]),
                $actor,
            );
        } catch (Throwable $e) {
            // A failure is recorded, not swallowed. The decision has already
            // been taken and stands; what is missing is its rendering, and the
            // row says so.
            $document->update([
                'status' => DocumentStatus::Failed->value,
                'failure_reason' => $e->getMessage(),
            ]);

            $this->timeline->record(
                'dpc_proceeding.document_failed',
                $proceeding->proposal,
                $type->label().' could not be generated',
                array_merge($this->eventProperties($document->fresh()), [
                    'failure_reason' => $e->getMessage(),
                ]),
                $actor,
            );
        }

        return $document->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function eventProperties(DpcProceedingDocument $document): array
    {
        return [
            'proceeding_uuid' => $document->proceeding_uuid,
            'document_uuid' => $document->uuid,
            'document_type' => $document->type->value,
            'document_version' => $document->document_version,
            'generator_version' => $document->generator_version,
            'generator_commit_hash' => $document->generator_commit_hash,
            'calculation_engine_version' => $document->calculation_engine_version,
            'final_calculation_uuid' => $document->final_calculation_uuid,
            'proposal_uuid' => $document->proposal_uuid,
        ];
    }

    /**
     * Re-issuing produces a new version rather than overwriting: the artefact
     * a department was already given must not change afterwards.
     */
    private function nextVersion(DpcProceeding $proceeding, DocumentType $type): int
    {
        return (int) DpcProceedingDocument::query()
            ->where('dpc_proceeding_id', $proceeding->id)
            ->where('type', $type->value)
            ->max('document_version') + 1;
    }

    private function assertApproved(DpcProceeding $proceeding): void
    {
        if ($proceeding->final_calculation_run_id === null || $proceeding->approved_at === null) {
            throw new WorkflowException(
                'Documents are produced from an approved result. This DPC has not been approved.'
            );
        }
    }
}
