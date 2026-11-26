<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Proceeding\Documents\HindiPdfRenderer;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcResultDocument;
use App\Domain\Proceeding\Models\DpcResultDocumentSignature;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Exceptions\SignatureVerificationFailed;
use App\Domain\Signature\Services\SignatureService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A DPC's result documents (2026-11-25): made as Hindi PDFs when the result is
 * approved — the proceedings (कार्यवाही विवरण) and one sealed-cover recommendation for
 * each officer kept in a sealed cover — then eSigned by every committee member, in any
 * order. One eSign covers them all. The eSign is the portal's own record — who
 * signed and when, on the documents' digests — never a certificate-based digital
 * signature embedded in the PDF. When the last member has signed, each is made
 * again from the same snapshot with every signature on it, and is final: only then
 * does the department see them.
 */
final class ResultDocumentService
{
    public function __construct(
        private readonly ResultDocumentData $data,
        private readonly HindiPdfRenderer $renderer,
        private readonly ActivityTimelineService $timeline,
    ) {}

    /**
     * Make the documents of an approved result — once: a second call returns them.
     *
     * @return Collection<int, DpcResultDocument>
     *
     * @throws WorkflowException
     */
    public function generate(DpcProceeding $proceeding, ?User $actor = null): Collection
    {
        if ($proceeding->status !== DpcProceedingStatus::ResultApproved) {
            throw new WorkflowException((string) __('proceeding.result_documents.not_approved'));
        }

        return DB::transaction(function () use ($proceeding, $actor): Collection {
            DpcProceeding::query()->whereKey($proceeding->id)->lockForUpdate()->first();

            $existing = $this->documents($proceeding);
            if ($existing->isNotEmpty()) {
                return $existing;
            }

            $built = $this->data->build($proceeding);
            $this->store($proceeding, DpcResultDocument::KARYAVAHI, $built['karyavahi'], $actor, ['format' => $built['karyavahi']['format']]);
            foreach ($built['sealed_covers'] as $cover) {
                $this->store($proceeding, DpcResultDocument::SEALED_COVER, $cover, $actor, [
                    'dpc_calculation_result_id' => $cover['result_id'], 'sn' => $cover['sn'], 'officer_name' => $cover['name'],
                ]);
            }

            $this->timeline->record('dpc_result.documents_generated', $proceeding->proposal, sprintf('Result documents made: proceedings and %d sealed cover(s)', count($built['sealed_covers'])), [
                'proceeding_uuid' => $proceeding->uuid,
            ], $actor);

            return $this->documents($proceeding);
        });
    }

    /** @return Collection<int, DpcResultDocument> */
    public function documents(DpcProceeding $proceeding): Collection
    {
        return DpcResultDocument::query()->where('dpc_proceeding_id', $proceeding->id)
            ->orderByRaw("CASE WHEN kind = 'karyavahi' THEN 0 ELSE 1 END")->orderBy('sn')->orderBy('id')->get();
    }

    /** @return Collection<int, DpcResultDocumentSignature> */
    public function signatures(DpcProceeding $proceeding): Collection
    {
        return DpcResultDocumentSignature::query()->where('dpc_proceeding_id', $proceeding->id)->orderBy('signed_at')->get();
    }

    /**
     * Who signs: every member on the proceedings as made — the committee that sat.
     *
     * @return list<array{user_id: int|null, name: string, designation: string, chairman: bool, represents: list<string>}>
     */
    public function signers(DpcProceeding $proceeding): array
    {
        $karyavahi = $this->documents($proceeding)->firstWhere('kind', DpcResultDocument::KARYAVAHI);

        return $karyavahi === null ? [] : array_values((array) ($karyavahi->data['members'] ?? []));
    }

    public function isFinal(DpcProceeding $proceeding): bool
    {
        $documents = $this->documents($proceeding);

        return $documents->isNotEmpty() && $documents->every(fn (DpcResultDocument $d): bool => $d->finalized_at !== null);
    }

    /** Why this user cannot sign now, or null when they can. */
    public function signRefusal(DpcProceeding $proceeding, User $user): ?string
    {
        if ($this->documents($proceeding)->isEmpty()) {
            return (string) __('proceeding.result_documents.none_yet');
        }
        if ($this->isFinal($proceeding)) {
            return (string) __('proceeding.result_documents.already_final');
        }
        if (! in_array($user->id, array_column($this->signers($proceeding), 'user_id'), true)) {
            return (string) __('proceeding.result_documents.not_a_signer');
        }
        if ($this->signatures($proceeding)->contains('user_id', $user->id)) {
            return (string) __('proceeding.result_documents.already_signed');
        }

        return null;
    }

    /**
     * One member's eSign on every result document; the last one makes them final.
     *
     * @throws WorkflowException
     * @throws SignatureVerificationFailed the eSign did not verify; nothing is stored
     */
    public function sign(DpcProceeding $proceeding, User $user): DpcResultDocumentSignature
    {
        return DB::transaction(function () use ($proceeding, $user): DpcResultDocumentSignature {
            DpcProceeding::query()->whereKey($proceeding->id)->lockForUpdate()->first();

            if (($refusal = $this->signRefusal($proceeding, $user)) !== null) {
                throw new WorkflowException($refusal);
            }

            $documents = $this->documents($proceeding);
            $digest = $this->bundleDigest($documents);
            $member = collect($this->signers($proceeding))->firstWhere('user_id', $user->id);

            $signature = app(SignatureService::class)->signVerified($user, $proceeding, implode("\n", [
                'DPC RESULT DOCUMENTS SIGNED',
                'DPC: '.(string) ($proceeding->proposal->dpc_code ?? $proceeding->proposal->uuid),
                ...$documents->map(fn (DpcResultDocument $d): string => '- '.$d->filename(false).' SHA-256 '.$d->sha256)->all(),
                'Bundle SHA-256: '.$digest,
                'Signed by: '.$user->name,
                'Date: '.now()->toDateTimeString(),
            ]), 'dpc-result-'.$proceeding->uuid.'-'.$user->id.'.txt', ['action' => 'result_documents']);

            $record = DpcResultDocumentSignature::query()->create([
                'dpc_proceeding_id' => $proceeding->id,
                'user_id' => $user->id,
                'member_name' => (string) ($member['name'] ?? $user->name),
                'designation' => $member['designation'] ?? null,
                'bundle_sha256' => $digest,
                'signature_id' => $signature->id,
                'signed_at' => now(),
            ]);

            $this->timeline->record('dpc_result.documents_signed', $proceeding->proposal, sprintf('%s eSigned the result documents', $user->name), [
                'proceeding_uuid' => $proceeding->uuid,
            ], $user);

            // Final only once every member of the committee that sat has signed: a member
            // without a login is awaited too, never skipped.
            $signed = $this->signatures($proceeding)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
            $awaited = array_filter(array_column($this->signers($proceeding), 'user_id'), fn ($id): bool => $id === null || ! in_array((int) $id, $signed, true));
            if ($awaited === []) {
                if (! $this->verify($proceeding)) {
                    throw new WorkflowException((string) __('proceeding.result_documents.integrity_failed'));
                }
                $this->finalize($proceeding, $documents);
            }

            return $record;
        });
    }

    /** Who may read the documents: the committee and the Commission always, the department once final. */
    public function mayView(DpcProceeding $proceeding, User $user): bool
    {
        $proposal = $proceeding->proposal;

        return app(CommitteeSittingService::class)->isSeatedOn($proposal, $user)
            || Gate::forUser($user)->allows('view', $proceeding)
            || ($this->isFinal($proceeding) && Gate::forUser($user)->allows('view', $proposal));
    }

    /**
     * The PDF: its signed copy once final, else the copy for signing — only while its
     * bytes still have the digest recorded when it was made.
     *
     * @throws WorkflowException the file is missing or no longer matches its digest
     */
    public function contents(DpcResultDocument $document): string
    {
        $final = $document->final_path !== null;
        $contents = Storage::disk($document->disk)->get($final ? (string) $document->final_path : $document->path);

        if ($contents === null || ! hash_equals((string) ($final ? $document->final_sha256 : $document->sha256), hash('sha256', $contents))) {
            throw new WorkflowException((string) __('proceeding.result_documents.integrity_failed'));
        }

        return $contents;
    }

    /**
     * Whether every document's file still matches its digest, the copy each member
     * signed included, and every signature was given on the documents as they are.
     */
    public function verify(DpcProceeding $proceeding): bool
    {
        $documents = $this->documents($proceeding);

        foreach ($documents as $document) {
            $draft = Storage::disk($document->disk)->get($document->path);
            if ($draft === null || ! hash_equals($document->sha256, hash('sha256', $draft))) {
                return false;
            }
            if ($document->final_path !== null) {
                $signed = Storage::disk($document->disk)->get($document->final_path);
                if ($signed === null || ! hash_equals((string) $document->final_sha256, hash('sha256', $signed))) {
                    return false;
                }
            }
        }

        $digest = $this->bundleDigest($documents);

        return $this->signatures($proceeding)->every(fn (DpcResultDocumentSignature $s): bool => hash_equals($s->bundle_sha256, $digest));
    }

    /** @param  Collection<int, DpcResultDocument>  $documents */
    public function bundleDigest(Collection $documents): string
    {
        return hash('sha256', $documents->sortBy('id')->map(fn (DpcResultDocument $d): string => $d->uuid.':'.$d->sha256)->implode("\n"));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $attributes
     */
    private function store(DpcProceeding $proceeding, string $kind, array $data, ?User $actor, array $attributes): DpcResultDocument
    {
        $contents = $this->renderer->render($this->view($kind), ['d' => $data, 'signatures' => [], 'final' => false, 'digest' => null]);
        $disk = (string) config('dpc.documents.disk', 'local');
        $uuid = (string) Str::uuid();
        $path = 'result-documents/'.$proceeding->uuid.'/'.$uuid.'.pdf';

        // The file first: a row never points at a file that was not written.
        Storage::disk($disk)->put($path, $contents);

        return DpcResultDocument::query()->create($attributes + [
            'uuid' => $uuid,
            'dpc_proceeding_id' => $proceeding->id,
            'kind' => $kind,
            'data' => $data,
            'disk' => $disk,
            'path' => $path,
            'sha256' => hash('sha256', $contents),
            'generated_at' => now(),
            'generated_by' => $actor?->id,
        ]);
    }

    /** @param  Collection<int, DpcResultDocument>  $documents */
    private function finalize(DpcProceeding $proceeding, Collection $documents): void
    {
        $signatures = $this->signatures($proceeding)
            ->mapWithKeys(fn (DpcResultDocumentSignature $s): array => [$s->user_id => ['at' => $s->signed_at->format('d.m.Y H:i')]])
            ->all();

        foreach ($documents as $document) {
            $contents = $this->renderer->render($this->view($document->kind), [
                'd' => $document->data, 'signatures' => $signatures, 'final' => true, 'digest' => $document->sha256,
            ]);
            $path = 'result-documents/'.$proceeding->uuid.'/'.$document->uuid.'-signed.pdf';
            Storage::disk($document->disk)->put($path, $contents);
            $document->forceFill(['final_path' => $path, 'final_sha256' => hash('sha256', $contents), 'finalized_at' => now()])->save();
        }

        $this->timeline->record('dpc_result.documents_final', $proceeding->proposal, 'Every committee member eSigned: the result documents are final', [
            'proceeding_uuid' => $proceeding->uuid,
        ]);
    }

    private function view(string $kind): string
    {
        return $kind === DpcResultDocument::KARYAVAHI ? 'documents.result.karyavahi' : 'documents.result.sealed-cover';
    }
}
