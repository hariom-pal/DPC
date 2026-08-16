<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Circulation\Documents\CirculationDocumentRenderer;
use App\Domain\Circulation\Enums\CirculationDocumentStatus;
use App\Domain\Circulation\Enums\CirculationDocumentType;
use App\Domain\Circulation\Enums\CirculationStatus;
use App\Domain\Circulation\Exceptions\PendingBusinessDecisionException;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Circulation\Models\DpcCirculationDocument;
use App\Domain\Circulation\ValueObjects\PendingBusinessDecision;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Services\SignatureService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The prescribed form — render → store → SHA-256 → sign → verify → publish
 * (PPT S45, S48; C3/C7) — M9 Phase 3.
 *
 * **M9's own pipeline, by architect ruling (AQ-22).** M7's
 * `ProceedingDocumentService` is neither used nor modified: every one of its
 * entry points is hard-typed to `DpcProceeding`, and a Circulation has none
 * (AD-24). What is reused is `SignatureService` — shared infrastructure that
 * signs any model, and which Phases 1 and 2 already use — and M7's *pattern*.
 *
 * **The duplication AQ-22 accepts is mitigated by asserting the same
 * properties M7 asserts**, not by sharing its code: stored bytes are never
 * rewritten, the hash is taken over stored bytes, `verify()` reads the stored
 * artefact, and a published document stays downloadable.
 *
 * **Generation and publication are separate, and that is the whole of §23.1.**
 * A document is rendered, stored, hashed, signed and verifiable regardless of
 * BD-14. **Publication is what BD-14 blocks**, because publication is the
 * point at which the document leaves the Commission and a department may act
 * on it — and an effective date is precisely what a department would act on.
 *
 * Nothing here calculates, scores, determines eligibility or a zone, or
 * conducts a proceeding (AD-23).
 */
class CirculationDocumentService
{
    public function __construct(
        private readonly CirculationDocumentRenderer $renderer,
        private readonly SignatureService $signatures,
        private readonly ActivityTimelineService $timeline,
    ) {}

    /**
     * Generate both documents for a signed circulation.
     *
     * @return EloquentCollection<int, DpcCirculationDocument>
     */
    public function generate(DpcCirculation $circulation, User $actor): EloquentCollection
    {
        $this->assertSigned($circulation);

        $documents = new EloquentCollection;

        foreach (CirculationDocumentType::cases() as $type) {
            $documents->push($this->generateOne($circulation, $actor, $type));
        }

        return $documents;
    }

    /**
     * **§23.1 — publication is refused while BD-14 is unresolved.**
     *
     * The refusal carries the structured decision Phase 1 established, so a
     * screen can display *"Pending Business Decision (BD-14)"* and say what
     * the system deliberately did not do. A document that simply never
     * published, with no reason given, reads as a system fault — and the
     * response to a fault is to retry, escalate, or work around it.
     *
     * @throws PendingBusinessDecisionException while BD-14 is open
     */
    public function publish(DpcCirculation $circulation, User $actor): void
    {
        $this->assertSigned($circulation);

        $pending = $this->assessPublication($circulation);

        if ($pending !== null) {
            $this->recordPendingDecision($circulation, $actor, $pending);

            throw new PendingBusinessDecisionException($pending);
        }

        $documents = $this->documents($circulation);

        if ($documents->isEmpty()
            || $documents->contains(fn (DpcCirculationDocument $d): bool => ! $d->isAvailable())) {
            throw new WorkflowException(
                'The documents for this circulation have not all been generated, so nothing can be published.'
            );
        }

        DB::transaction(function () use ($circulation, $actor, $documents): void {
            foreach ($documents as $document) {
                if (! $document->isPublished()) {
                    $document->update(['published_at' => now()]);
                }
            }

            $this->timeline->record(
                'dpc_circulation.documents_published',
                $circulation->originProposal,
                'Circulation documents published to the department',
                [
                    'circulation_uuid' => $circulation->uuid,
                    'circulation_code' => $circulation->circulation_code,
                    'document_uuids' => $documents->pluck('uuid')->all(),
                    'published_at' => now()->toIso8601String(),
                ],
                $actor,
            );
        });
    }

    /**
     * Whether publication is blocked, and why — `null` when it may proceed.
     *
     * **BD-14 is expressed as configuration** (AD-3), not as a constant: when
     * CGPSC rules, the answer is applied by setting
     * `dpc.circulation.effective_date_basis`, with no code change and no
     * redeploy of business logic.
     */
    public function assessPublication(DpcCirculation $circulation): ?PendingBusinessDecision
    {
        $basis = config('dpc.circulation.effective_date_basis');

        if (is_string($basis) && $basis !== '') {
            return null;
        }

        return new PendingBusinessDecision(
            code: 'BD-14',
            title: 'Pending Business Decision (BD-14)',
            question: 'From what date does a circulated promotion take effect — retrospectively to the '
                .'original DPC\'s year, or from the date of the Commission\'s consent? Retrospective '
                .'seniority reaches every officer below the one promoted. Awaiting CGPSC.',
            affected: $circulation->items
                ->filter(static fn ($item): bool => $item->hold_released_at !== null)
                ->map(static fn ($item): array => [
                    'sn' => $item->sn,
                    'officer_name' => $item->officer_name,
                    'hold_released_at' => $item->hold_released_at?->toIso8601String(),
                ])->values()->all(),
            notDone: [
                'No effective date was invented; the documents state the facts and assert neither as governing.',
                'The documents are generated, stored, hashed, signed and verifiable — publication alone is refused.',
                'No hold was re-opened and no promotion was withdrawn.',
            ],
            route: 'docs/PENDING_BUSINESS_DECISIONS.md',
        );
    }

    /**
     * The current version of each document, newest first.
     *
     * @return EloquentCollection<int, DpcCirculationDocument>
     */
    public function documents(DpcCirculation $circulation): EloquentCollection
    {
        /** @var EloquentCollection<int, DpcCirculationDocument> $current */
        $current = DpcCirculationDocument::query()
            ->where('dpc_circulation_id', $circulation->id)
            ->orderByDesc('document_version')
            ->get()
            ->unique('type')
            ->values();

        return $current;
    }

    /**
     * Every document ever generated, superseded versions included — document
     * history. Regenerating never overwrites, which is what makes this
     * answerable at all.
     *
     * @return EloquentCollection<int, DpcCirculationDocument>
     */
    public function allVersions(DpcCirculation $circulation): EloquentCollection
    {
        return DpcCirculationDocument::query()
            ->where('dpc_circulation_id', $circulation->id)
            ->orderBy('type')
            ->orderByDesc('document_version')
            ->get();
    }

    public function contents(DpcCirculationDocument $document): string
    {
        if (! $document->isAvailable()) {
            throw new WorkflowException('That document has not been generated.');
        }

        return (string) Storage::disk((string) $document->disk)->get((string) $document->path);
    }

    /**
     * Whether the stored file still matches the hash taken when it was
     * generated.
     *
     * The hash is not decoration: it is how a department's copy is shown to be
     * the copy the Commission issued.
     */
    public function verify(DpcCirculationDocument $document): bool
    {
        return hash('sha256', $this->contents($document)) === $document->content_hash;
    }

    // ------------------------------------------------------------- internals

    private function generateOne(
        DpcCirculation $circulation,
        User $actor,
        CirculationDocumentType $type,
    ): DpcCirculationDocument {
        $version = $this->nextVersion($circulation, $type);

        // The row exists before the file does — a generation that never
        // completes then leaves visible evidence of an expected-but-absent
        // legal document rather than silence.
        $document = DpcCirculationDocument::query()->create([
            'dpc_circulation_id' => $circulation->id,
            'type' => $type->value,
            'status' => CirculationDocumentStatus::Pending->value,
            'circulation_uuid' => $circulation->uuid,
            'origin_proposal_uuid' => (string) $circulation->originProposal?->uuid,
            'origin_calculation_uuid' => $circulation->originProceeding?->finalCalculationRun?->uuid,
            'circulation_code' => $circulation->circulation_code,
            'origin_dpc_code' => $circulation->origin_dpc_code,
            'document_version' => $version,
            'generator_version' => $this->renderer->version(),
            'generator_commit_hash' => (string) config('dpc.application.git_commit'),
            'generated_by' => $actor->id,
            'generated_by_name' => $actor->name,
        ]);

        try {
            $contents = $this->renderer->render($circulation, $type);

            $disk = (string) config('dpc.circulation.documents.disk', 'local');
            $path = 'circulations/'.$circulation->uuid.'/'.$document->uuid.'-'
                .$type->value.'.'.$this->renderer->extension();

            Storage::disk($disk)->put($path, $contents);

            $signature = $this->signatures->sign(
                $actor,
                $document,
                $contents,
                $type->filename(),
                [
                    'circulation_uuid' => $circulation->uuid,
                    'circulation_code' => $circulation->circulation_code,
                    'origin_dpc_code' => $circulation->origin_dpc_code,
                    'document_version' => $version,
                ],
            );

            $document->update([
                'status' => CirculationDocumentStatus::Generated->value,
                'disk' => $disk,
                'path' => $path,
                'content_hash' => hash('sha256', $contents),
                'signature_id' => $signature->id,
                'generated_at' => now(),
            ]);

            $this->timeline->record(
                'dpc_circulation.document_generated',
                $circulation->originProposal,
                $type->label().' generated',
                array_merge(
                    ['circulation_uuid' => $circulation->uuid],
                    $document->fresh()->toTimelineProperties(),
                ),
                $actor,
            );
        } catch (Throwable $e) {
            // Recorded, not swallowed. A failed legal document must be visible
            // as a failure rather than as an absence (AD-18).
            $document->update([
                'status' => CirculationDocumentStatus::Failed->value,
                'failure_reason' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $document->fresh();
    }

    private function nextVersion(DpcCirculation $circulation, CirculationDocumentType $type): int
    {
        return 1 + (int) DpcCirculationDocument::query()
            ->where('dpc_circulation_id', $circulation->id)
            ->where('type', $type->value)
            ->max('document_version');
    }

    /**
     * Documents are produced from a **signed** circulation and no earlier.
     * Before the signature nothing has been decided — a note that is consented
     * but never signed leaves every promotion reserved (AQ-17), and a
     * prescribed form rendered from it would state a consent that has no
     * effect.
     */
    private function assertSigned(DpcCirculation $circulation): void
    {
        if ($circulation->status !== CirculationStatus::Signed) {
            throw new WorkflowException(
                'The circulation note has not been signed by the Commission, so no document can be '
                .'produced from it.'
            );
        }
    }

    private function recordPendingDecision(
        DpcCirculation $circulation,
        User $actor,
        PendingBusinessDecision $pending,
    ): void {
        $this->timeline->record(
            'dpc_circulation.publication_pending_business_decision',
            $circulation->originProposal,
            $pending->summary(),
            array_merge(['circulation_uuid' => $circulation->uuid], $pending->toArray()),
            $actor,
        );
    }
}
