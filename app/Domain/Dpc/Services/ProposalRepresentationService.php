<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcProposalRepresentation;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Coder Instructions rule 4.4: every representation, complaint or letter received in
 * connection with a DPC is uploaded with a summary of it. Kept, never deleted; the
 * department's undertaking that none affects the DPC is the rule 1.1 checklist item
 * `representations`, which a later upload makes stale.
 */
final class ProposalRepresentationService
{
    public function __construct(private readonly ActivityTimelineService $timeline) {}

    /**
     * @param  array{summary: string, received_from?: string|null, received_on?: string|null}  $data
     */
    public function upload(DpcProposal $proposal, array $data, UploadedFile $document, User $actor): DpcProposalRepresentation
    {
        if (! $proposal->status->isEditable()) {
            throw new WorkflowException(__('dpc.representations.not_editable'));
        }

        $disk = (string) config('dpc.documents.disk', 'local');
        $contents = (string) file_get_contents($document->getRealPath());
        $path = Storage::disk($disk)->putFile(sprintf('private/proposal-representations/%d', $proposal->id), $document);

        if ($path === false) {
            throw new WorkflowException(__('dpc.representations.store_failed'));
        }

        $representation = DpcProposalRepresentation::query()->create([
            'dpc_proposal_id' => $proposal->id,
            'received_from' => $data['received_from'] ?? null,
            'received_on' => $data['received_on'] ?? null,
            'summary' => trim($data['summary']),
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr((string) $document->getClientOriginalName(), 0, 255),
            'size_bytes' => $document->getSize() ?: 0,
            'sha256' => hash('sha256', $contents),
            'uploaded_by' => $actor->id,
            'uploaded_at' => now(),
        ]);

        $this->timeline->record('dpc_proposal.representation_uploaded', $proposal, __('dpc.representations.timeline', [
            'name' => $representation->original_name,
        ]), ['representation_id' => $representation->id, 'sha256' => $representation->sha256], $actor);

        return $representation;
    }
}
