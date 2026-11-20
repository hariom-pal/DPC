<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Services;

use App\Domain\CandidateVerification\Enums\GrievanceStatus;
use App\Domain\CandidateVerification\Enums\ParticipantStatus;
use App\Domain\CandidateVerification\Enums\VerificationSection;
use App\Domain\CandidateVerification\Models\Grievance;
use App\Domain\CandidateVerification\Models\VerificationCycle;
use App\Domain\CandidateVerification\Models\VerificationDecision;
use App\Domain\CandidateVerification\Models\VerificationParticipant;
use App\Domain\CandidateVerification\Models\VerificationSnapshot;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Domain\Signature\Exceptions\SignatureVerificationFailed;
use App\Domain\Signature\Services\SignatureAttemptLog;
use App\Domain\Signature\Services\SignatureService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A candidate's verification (2026-11-19): a decision on each of the four sections —
 * agreed, or a grievance with its remark, PDF and undertaking — changed freely as a
 * draft, then submitted once, together, under one eSign. The candidate never changes
 * a departmental record: only their own decisions and grievances.
 *
 * ONE CANDIDATE, ONE DPC, ONE SUBMISSION: enforced here under a lock on the cycle and
 * the participant, and in the database (one participant per officer per DPC, one
 * grievance per section). A repeat of the final step (a second click, a replayed
 * callback) returns the first submission and changes nothing.
 */
final class CandidateVerificationService
{
    public function __construct(
        private readonly CandidateRecordView $view,
        private readonly CandidateAccounts $accounts,
        private readonly SignatureService $signatures,
        private readonly ActivityTimelineService $timeline,
    ) {}

    /** The participant has looked at their record. */
    public function opened(VerificationParticipant $participant): void
    {
        if ($participant->status === ParticipantStatus::NotStarted && $participant->cycle->acceptsSubmissions()) {
            $participant->forceFill(['status' => ParticipantStatus::InProgress->value, 'first_viewed_at' => now()])->save();
        }
    }

    /** @throws WorkflowException */
    public function agree(VerificationParticipant $participant, VerificationSection $section): void
    {
        DB::transaction(function () use ($participant, $section): void {
            $locked = $this->lockOpen($participant);
            $this->dropDraft($locked, $section);
            $this->decide($locked, $section, VerificationDecision::AGREED);
        });
    }

    /**
     * Save (or replace) the section's draft grievance.
     *
     * @param  array{category: string, related_year?: int|null, remark: string}  $data
     *
     * @throws WorkflowException
     */
    public function saveGrievance(VerificationParticipant $participant, VerificationSection $section, array $data, ?UploadedFile $document): Grievance
    {
        if (! in_array($data['category'], $section->categories(), true)) {
            throw new WorkflowException((string) __('candidate.grievance.errors.category'));
        }

        $year = $data['related_year'] ?? null;
        if ($year !== null && ! in_array((int) $year, $this->view->yearsFor($participant->row, $section), true)) {
            throw new WorkflowException((string) __('candidate.grievance.errors.year'));
        }

        $stored = $document === null ? null : $this->store($participant, $section, $document);

        try {
            return DB::transaction(function () use ($participant, $section, $data, $year, $stored): Grievance {
                $locked = $this->lockOpen($participant);
                $existing = Grievance::query()->where('dpc_verification_participant_id', $locked->id)->where('section', $section->value)->first();

                if ($existing === null && $stored === null) {
                    throw new WorkflowException((string) __('candidate.grievance.errors.document'));
                }

                $values = [
                    'category' => $data['category'],
                    'related_year' => $year === null ? null : (int) $year,
                    'remark' => trim($data['remark']),
                    'undertaking_accepted_at' => now(),
                    'status' => GrievanceStatus::Draft->value,
                    ...($stored ?? []),
                ];

                if ($existing !== null && $stored !== null) {
                    // A draft's file is not evidence until submitted: the replaced one goes.
                    Storage::disk($existing->disk)->delete($existing->path);
                }

                if ($existing === null) {
                    $grievance = Grievance::query()->create([
                        'dpc_verification_participant_id' => $locked->id,
                        'dpc_verification_cycle_id' => $locked->dpc_verification_cycle_id,
                        'section' => $section->value,
                        ...$values,
                    ]);
                } else {
                    $existing->forceFill($values)->save();
                    $grievance = $existing;
                }

                $this->decide($locked, $section, VerificationDecision::GRIEVANCE);

                return $grievance->fresh() ?? $grievance;
            });
        } catch (\Throwable $e) {
            if ($stored !== null) {
                Storage::disk($stored['disk'])->delete($stored['path']);
            }

            throw $e;
        }
    }

    /**
     * Remove the section's draft grievance; the section is undecided again.
     *
     * @throws WorkflowException
     */
    public function removeGrievance(VerificationParticipant $participant, VerificationSection $section): void
    {
        DB::transaction(function () use ($participant, $section): void {
            $locked = $this->lockOpen($participant);
            $this->dropDraft($locked, $section);
            VerificationDecision::query()->where('dpc_verification_participant_id', $locked->id)->where('section', $section->value)->delete();
        });
    }

    /**
     * The decisions so far: section => agreed | grievance | null.
     *
     * @return array<string, string|null>
     */
    public function decisions(VerificationParticipant $participant): array
    {
        $made = VerificationDecision::query()->where('dpc_verification_participant_id', $participant->id)->pluck('decision', 'section')->all();
        $all = [];
        foreach (VerificationSection::cases() as $section) {
            $all[$section->value] = $made[$section->value] ?? null;
        }

        return $all;
    }

    /**
     * What still stops the final submission, or an empty list.
     *
     * @return list<string>
     */
    public function blockers(VerificationParticipant $participant): array
    {
        $blockers = [];
        if (! $participant->cycle->acceptsSubmissions()) {
            $blockers[] = (string) __('candidate.verification.errors.closed');
        }
        if ($participant->hasSubmitted()) {
            $blockers[] = (string) __('candidate.verification.errors.already_submitted');
        }

        $grievances = Grievance::query()->where('dpc_verification_participant_id', $participant->id)->pluck('section')->map(fn ($s) => $s instanceof VerificationSection ? $s->value : (string) $s)->all();
        foreach ($this->decisions($participant) as $section => $decision) {
            if ($decision === null || ($decision === VerificationDecision::GRIEVANCE && ! in_array($section, $grievances, true))) {
                $blockers[] = (string) __('candidate.verification.errors.section_pending', ['section' => VerificationSection::from($section)->label()]);
            }
        }

        return $blockers;
    }

    /**
     * The document the candidate signs, and its snapshot: their record as shown, their
     * four decisions, their grievances with each PDF's hash, and the undertaking.
     *
     * @return array{snapshot: array<string, mixed>, sha256: string, document: string}
     */
    public function signable(VerificationParticipant $participant): array
    {
        $row = $participant->row;
        $grievances = Grievance::query()->where('dpc_verification_participant_id', $participant->id)->orderBy('section')->get();
        $snapshot = [
            'cycle' => $participant->cycle->uuid,
            'participant' => $participant->uuid,
            'dpc' => CandidateRecordView::reference($row->proposal),
            'candidate' => ['name' => $row->name, 'employee_number' => $row->employee_number, 'seniority_number' => $row->sn],
            'deadline' => $participant->cycle->deadline_at->toIso8601String(),
            'decisions' => $this->decisions($participant),
            'grievances' => $grievances->map(fn (Grievance $g): array => [
                'section' => $g->section->value, 'category' => $g->category, 'related_year' => $g->related_year,
                'remark' => $g->remark, 'document' => $g->original_name, 'document_sha256' => $g->sha256,
            ])->values()->all(),
            'undertaking' => (string) __('candidate.grievance.undertaking', [], 'en'),
            'record' => $this->view->all($row),
        ];
        $sha = hash('sha256', (string) json_encode($snapshot));

        $lines = [
            'CANDIDATE VERIFICATION — '.$snapshot['dpc'],
            'Candidate: '.$row->name.' (Employee ID '.($row->employee_number ?? '—').', Seniority No. '.$row->sn.')',
            'Verification cycle: '.$participant->cycle->uuid,
        ];
        foreach ($snapshot['decisions'] as $section => $decision) {
            $lines[] = VerificationSection::from($section)->label().': '.($decision === VerificationDecision::AGREED ? 'Agreed' : 'Grievance');
        }
        foreach ($snapshot['grievances'] as $g) {
            $lines[] = 'Grievance ['.$g['section'].', '.$g['category'].($g['related_year'] ? ', '.$g['related_year'] : '').']: '.$g['remark'].' — document '.$g['document'].' (SHA-256 '.$g['document_sha256'].')';
        }
        if ($snapshot['grievances'] !== []) {
            $lines[] = 'Undertaking: '.$snapshot['undertaking'];
        }
        $lines[] = 'Record snapshot SHA-256: '.$sha;

        return ['snapshot' => $snapshot, 'sha256' => $sha, 'document' => implode("\n", $lines)];
    }

    /**
     * The final submission, signed: once only, before the deadline. Nothing is final
     * before the eSign verifies; a failed eSign leaves the drafts as they were.
     *
     * @throws WorkflowException
     */
    public function submit(VerificationParticipant $participant): VerificationParticipant
    {
        $signer = $this->accounts->userFor($participant->row)
            ?? throw new WorkflowException((string) __('candidate.verification.errors.no_account'));

        try {
            return DB::transaction(function () use ($participant, $signer): VerificationParticipant {
                // The cycle first, as the deadline's closing locks it: a submission and
                // the close never cross — whichever locks first decides.
                VerificationCycle::query()->whereKey($participant->dpc_verification_cycle_id)->lockForUpdate()->firstOrFail();
                /** @var VerificationParticipant $locked */
                $locked = VerificationParticipant::query()->whereKey($participant->id)->lockForUpdate()->firstOrFail();

                // Already submitted: the same answer, nothing changed (a repeat or a replayed callback).
                if ($locked->hasSubmitted()) {
                    return $locked;
                }

                if (($blockers = $this->blockers($locked)) !== []) {
                    throw new WorkflowException($blockers[0]);
                }

                $signable = $this->signable($locked);
                $signature = $this->signatures->signVerified($signer, $locked, $signable['document'], 'candidate-verification-'.$locked->uuid.'.txt', ['action' => 'candidate_verification']);
                $hasGrievance = in_array(VerificationDecision::GRIEVANCE, $signable['snapshot']['decisions'], true);

                $locked->forceFill([
                    'status' => ($hasGrievance ? ParticipantStatus::GrievanceSubmitted : ParticipantStatus::VerifiedAgreed)->value,
                    'submitted_at' => now(),
                    'signature_id' => $signature->id,
                    'submitted_snapshot' => $signable['snapshot'],
                    'snapshot_sha256' => $signable['sha256'],
                ])->save();

                Grievance::query()->where('dpc_verification_participant_id', $locked->id)->where('status', GrievanceStatus::Draft->value)
                    ->update(['status' => GrievanceStatus::Submitted->value, 'submitted_at' => now(), 'updated_at' => now()]);

                VerificationSnapshot::query()->create([
                    'dpc_verification_participant_id' => $locked->id, 'version' => 1, 'reason' => 'signed_submission',
                    'snapshot' => $signable['snapshot'], 'sha256' => $signable['sha256'], 'created_by' => $signer->id,
                ]);

                $this->timeline->record('dpc_proposal.candidate_verification_submitted', $locked->row->proposal, sprintf(
                    'Candidate %s (S.N. %d) submitted the verification: %s',
                    $locked->row->name, $locked->row->sn, $hasGrievance ? 'grievance(s) raised' : 'agreed',
                ), ['participant' => $locked->uuid, 'snapshot_sha256' => $signable['sha256'], 'signature_id' => $signature->id], $signer);

                return $locked;
            });
        } catch (SignatureVerificationFailed $e) {
            app(SignatureAttemptLog::class)->record($signer, $participant, 'candidate_verification', $e->reason, $e->assertedName);

            throw new WorkflowException($e->getMessage());
        }
    }

    /** @throws WorkflowException */
    private function lockOpen(VerificationParticipant $participant): VerificationParticipant
    {
        /** @var VerificationParticipant $locked */
        $locked = VerificationParticipant::query()->whereKey($participant->id)->lockForUpdate()->firstOrFail();

        if ($locked->hasSubmitted()) {
            throw new WorkflowException((string) __('candidate.verification.errors.already_submitted'));
        }
        if (! $locked->cycle->acceptsSubmissions()) {
            throw new WorkflowException((string) __('candidate.verification.errors.closed'));
        }

        if ($locked->status === ParticipantStatus::NotStarted) {
            $locked->forceFill(['status' => ParticipantStatus::InProgress->value, 'first_viewed_at' => $locked->first_viewed_at ?? now()])->save();
        }

        return $locked;
    }

    private function decide(VerificationParticipant $participant, VerificationSection $section, string $decision): void
    {
        VerificationDecision::query()->updateOrCreate(
            ['dpc_verification_participant_id' => $participant->id, 'section' => $section->value],
            ['decision' => $decision, 'decided_at' => now()],
        );
    }

    private function dropDraft(VerificationParticipant $participant, VerificationSection $section): void
    {
        $draft = Grievance::query()->where('dpc_verification_participant_id', $participant->id)->where('section', $section->value)->where('status', GrievanceStatus::Draft->value)->first();

        if ($draft !== null) {
            Storage::disk($draft->disk)->delete($draft->path);
            $draft->delete();
        }
    }

    /** @return array{disk: string, path: string, original_name: string, size_bytes: int, sha256: string} */
    private function store(VerificationParticipant $participant, VerificationSection $section, UploadedFile $file): array
    {
        $disk = (string) config('dpc.candidate_verification.documents_disk', 'local');
        $contents = (string) file_get_contents($file->getRealPath());
        $path = 'candidate-grievances/'.$participant->cycle->uuid.'/'.$participant->uuid.'/'.$section->value.'-'.Str::random(24).'.pdf';

        if (! Storage::disk($disk)->put($path, $contents)) {
            throw new WorkflowException((string) __('candidate.grievance.errors.store'));
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'size_bytes' => (int) $file->getSize(),
            'sha256' => hash('sha256', $contents),
        ];
    }
}
