<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Enums\PreviousDpcBasis;
use App\Domain\Dpc\Enums\ProposalVerificationItem;
use App\Domain\Dpc\Models\DpcEvaluationDocument;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcProposalRepresentation;
use App\Domain\Dpc\Models\DpcProposalVerification;
use App\Domain\Dpc\Models\DpcSeniorityEntry;
use App\Domain\PreviousDpc\Models\PreviousDpcRecord;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Rule 1.1 of the DPC Understanding Document — the department's pre-submission
 * confirmations.
 *
 * > *Before a DPC is started, confirm that the vacancy setup has been matched with
 * > the setup sanction order, the Recruitment Rules and the gradation list, and that
 * > the count of filled posts has been matched with the minutes of the previous DPC.*
 *
 * ### What this does not do
 *
 * It does not recompute anything. The sanctioned strength is the Department Post
 * Mapping's, the working strength is the Working Officers chart's, the rule is the
 * proposal's own Recruitment Rule and the list is the proposal's Seniority List — the
 * officer confirms that *those* figures were checked against the source documents.
 * No vacancy, reservation or CR rule is read or changed here.
 *
 * ### Why a confirmation remembers what it confirmed
 *
 * Each confirmation stores the figures it was given against (`basis`). If the
 * sanctioned strength, the rule version, the seniority list or the working strength
 * changes afterwards, the confirmation no longer describes the proposal, and the gate
 * asks for it again rather than letting a stale tick stand for data nobody checked.
 *
 * ### Why it is tied to the revision
 *
 * A return opens a new revision. Confirmations made for the returned revision are
 * kept as history but do not count for the next one — what the department verifies is
 * the version it submits.
 */
class ProposalVerificationService
{
    public function __construct(
        private readonly VacancyCalculationService $vacancies,
        private readonly VacancyEngine $engine,
        private readonly ActivityTimelineService $timeline,
    ) {}

    /**
     * Whether Rule 1.1 holds at the submission gate.
     */
    public function enforced(): bool
    {
        return (bool) config('dpc.proposal_verification.enforced', true);
    }

    /**
     * The state of each item for the proposal's current revision.
     *
     * @return list<array{
     *     item: ProposalVerificationItem,
     *     latest: DpcProposalVerification|null,
     *     confirmed: bool,
     *     stale: bool,
     *     complete: bool
     * }>
     */
    public function status(DpcProposal $proposal): array
    {
        $rows = DpcProposalVerification::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->where('revision_number', $proposal->revision_number)
            ->with('verifier', 'previousDpcProposal')
            ->orderBy('id')
            ->get();

        $status = [];

        foreach (ProposalVerificationItem::cases() as $item) {
            $latest = $rows->filter(fn (DpcProposalVerification $row): bool => $row->item === $item)->last();
            $confirmed = $latest !== null && $latest->isConfirmed();
            $stale = $confirmed && $latest->basis_hash !== $this->hash($this->basisFor($proposal, $item));

            $status[] = [
                'item' => $item,
                'latest' => $latest,
                'confirmed' => $confirmed,
                'stale' => $stale,
                'complete' => $confirmed && ! $stale,
            ];
        }

        return $status;
    }

    public function isComplete(DpcProposal $proposal): bool
    {
        foreach ($this->status($proposal) as $entry) {
            if (! $entry['complete']) {
                return false;
            }
        }

        return true;
    }

    /**
     * The labels of the items still outstanding, for the gate's message.
     *
     * @return list<string>
     */
    public function outstanding(DpcProposal $proposal): array
    {
        $outstanding = [];

        foreach ($this->status($proposal) as $entry) {
            if ($entry['complete']) {
                continue;
            }

            $outstanding[] = $entry['stale']
                ? __('dpc.verification.needs_reconfirmation', ['item' => $entry['item']->label()])
                : $entry['item']->label();
        }

        return $outstanding;
    }

    /**
     * Every statement ever made about this proposal, across revisions, oldest first.
     *
     * @return EloquentCollection<int, DpcProposalVerification>
     */
    public function history(DpcProposal $proposal): EloquentCollection
    {
        return $proposal->verifications()->with('verifier', 'previousDpcProposal')->get();
    }

    /**
     * Rule 1.1: the offline DPCs the Commission's staff recorded for this department
     * and promotion post, newest first.
     *
     * @return EloquentCollection<int, PreviousDpcRecord>
     */
    public function previousDpcRecords(DpcProposal $proposal): EloquentCollection
    {
        return PreviousDpcRecord::query()
            ->where('department_id', $proposal->department_id)
            ->whereHas('promotionToPost', fn ($query) => $query->where('name', $proposal->promotionToPost?->name))
            ->orderByDesc('held_on')
            ->get();
    }

    /**
     * Previous DPCs for the same post that the portal itself holds.
     *
     * Approved proposals of the same department for the same Promotion To Post — the
     * DPCs whose proceedings and promotion list the portal can show. The proposal
     * being verified is never its own previous DPC.
     *
     * @return EloquentCollection<int, DpcProposal>
     */
    public function previousDpcCandidates(DpcProposal $proposal): EloquentCollection
    {
        return DpcProposal::query()
            ->where('department_id', $proposal->department_id)
            ->where('promotion_to_post_id', $proposal->promotion_to_post_id)
            ->where('status', DpcProposalStatus::Approved->value)
            ->whereKeyNot($proposal->id)
            ->orderByDesc('approved_at')
            ->get();
    }

    /**
     * Record one confirmation.
     *
     * @param  array{basis?: string|null, proposal_id?: int|null, reference?: string|null, held_on?: string|null, record_id?: int|null}  $previousDpc
     *
     * @throws WorkflowException when the proposal is locked or the item cannot be confirmed yet
     */
    public function confirm(
        DpcProposal $proposal,
        ProposalVerificationItem $item,
        User $actor,
        ?string $remarks = null,
        array $previousDpc = [],
        ?UploadedFile $minutes = null,
        ?string $rrNotifiedOn = null,
    ): DpcProposalVerification {
        $this->assertEditable($proposal);
        $this->assertConfirmable($proposal, $item);

        // Rule 4.4: the date the latest Recruitment Rule was notified is part of the undertaking.
        if ($item === ProposalVerificationItem::RecruitmentRuleConformity && ($rrNotifiedOn === null || trim($rrNotifiedOn) === '')) {
            throw new WorkflowException(__('dpc.verification.items.recruitment_rule_conformity.date_required'));
        }

        $remarks = $remarks === null || trim($remarks) === '' ? null : trim($remarks);
        $reference = $item === ProposalVerificationItem::PreviousDpcReconciliation
            ? $this->previousDpcReference($proposal, $previousDpc, $remarks, $minutes)
            : [];

        return DB::transaction(function () use ($proposal, $item, $actor, $remarks, $reference, $minutes, $rrNotifiedOn): DpcProposalVerification {
            $basis = $this->basisFor($proposal, $item);

            $document = [];
            $record = isset($reference['previous_dpc_record_id']) ? PreviousDpcRecord::query()->find($reference['previous_dpc_record_id']) : null;

            if ($record !== null && $record->hasFile('minutes')) {
                // The staff's record carries the minutes; they are cited, not copied.
                $document = [
                    'disk' => $record->minutes_disk,
                    'path' => $record->minutes_path,
                    'original_name' => $record->minutes_original_name,
                    'size_bytes' => null,
                    'sha256' => $record->minutes_sha256,
                ];
            } elseif ($minutes !== null && ($reference['previous_dpc_basis'] ?? null) === PreviousDpcBasis::Offline->value) {
                $document = $this->storeMinutes($proposal, $minutes);
            }

            $verification = DpcProposalVerification::query()->create([
                'dpc_proposal_id' => $proposal->id,
                'revision_number' => $proposal->revision_number,
                'item' => $item->value,
                'status' => DpcProposalVerification::STATUS_CONFIRMED,
                'remarks' => $remarks,
                'basis' => $basis,
                'basis_hash' => $this->hash($basis),
                ...$reference,
                ...$document,
                'rr_notified_on' => $item === ProposalVerificationItem::RecruitmentRuleConformity ? $rrNotifiedOn : null,
                'verified_by' => $actor->id,
                'verified_at' => now(),
            ]);

            $this->timeline->record(
                'dpc_proposal.verification_confirmed',
                $proposal,
                __('dpc.verification.timeline.confirmed', [
                    'item' => $item->label(),
                    'revision' => $proposal->revision_number,
                ]),
                [
                    'item' => $item->value,
                    'revision_number' => $proposal->revision_number,
                    'basis' => $basis,
                    'previous_dpc_basis' => $reference['previous_dpc_basis'] ?? null,
                    'previous_dpc_proposal_id' => $reference['previous_dpc_proposal_id'] ?? null,
                    'previous_dpc_reference' => $reference['previous_dpc_reference'] ?? null,
                    'document_sha256' => $document['sha256'] ?? null,
                    'remarks' => $remarks,
                ],
                $actor,
            );

            return $verification;
        });
    }

    /**
     * Withdraw a confirmation. Recorded as a row of its own; the confirmation it
     * withdraws is kept.
     *
     * @throws WorkflowException when the proposal is locked or there is nothing to withdraw
     */
    public function withdraw(DpcProposal $proposal, ProposalVerificationItem $item, User $actor): DpcProposalVerification
    {
        $this->assertEditable($proposal);

        $current = collect($this->status($proposal))->firstWhere('item', $item);

        if ($current === null || ! $current['confirmed']) {
            throw new WorkflowException(__('dpc.verification.nothing_to_withdraw', ['item' => $item->label()]));
        }

        return DB::transaction(function () use ($proposal, $item, $actor): DpcProposalVerification {
            $verification = DpcProposalVerification::query()->create([
                'dpc_proposal_id' => $proposal->id,
                'revision_number' => $proposal->revision_number,
                'item' => $item->value,
                'status' => DpcProposalVerification::STATUS_WITHDRAWN,
                'verified_by' => $actor->id,
                'verified_at' => now(),
            ]);

            $this->timeline->record(
                'dpc_proposal.verification_withdrawn',
                $proposal,
                __('dpc.verification.timeline.withdrawn', [
                    'item' => $item->label(),
                    'revision' => $proposal->revision_number,
                ]),
                ['item' => $item->value, 'revision_number' => $proposal->revision_number],
                $actor,
            );

            return $verification;
        });
    }

    /**
     * The figures an item is confirmed against, read from where the proposal already
     * keeps them.
     *
     * @return array<string, int|string|null>
     */
    public function basisFor(DpcProposal $proposal, ProposalVerificationItem $item): array
    {
        return match ($item) {
            ProposalVerificationItem::SanctionedStrength => [
                'promotion_to_post_id' => $proposal->promotion_to_post_id,
                'sanctioned' => $this->vacancies->breakdown($proposal)->sanctionedStrength,
            ],
            ProposalVerificationItem::RecruitmentRule => (function () use ($proposal): array {
                // The rule the vacancy engine actually applies to this proposal —
                // the recorded one, or the one in force when none is recorded.
                $rule = $this->engine->recruitmentRule($proposal);

                return [
                    'recruitment_rule_id' => $rule?->id,
                    'version' => $rule?->version,
                    'government_order_number' => $rule?->government_order_number,
                ];
            })(),
            ProposalVerificationItem::SeniorityList => $this->seniorityFingerprint($proposal),
            ProposalVerificationItem::PreviousDpcReconciliation => [
                'working' => $this->vacancies->breakdown($proposal)->workingStrength,
            ],
            // A representation uploaded after the undertaking makes it stale.
            ProposalVerificationItem::Representations => [
                'representations' => DpcProposalRepresentation::query()
                    ->where('dpc_proposal_id', $proposal->id)->orderBy('id')->pluck('sha256')->all(),
            ],
            // A document uploaded after the undertaking makes it stale.
            ProposalVerificationItem::OriginalDocuments => [
                'documents' => DpcEvaluationDocument::query()->where('dpc_proposal_id', $proposal->id)->count(),
            ],
            ProposalVerificationItem::RecruitmentRuleConformity => [
                'recruitment_rule_id' => $this->engine->recruitmentRule($proposal)?->id,
                'officers' => $proposal->evaluationRows()->count(),
            ],
        };
    }

    /**
     * An item cannot be confirmed against data that is not there yet.
     */
    private function assertConfirmable(DpcProposal $proposal, ProposalVerificationItem $item): void
    {
        $basis = $this->basisFor($proposal, $item);

        $missing = match ($item) {
            ProposalVerificationItem::SanctionedStrength => $basis['sanctioned'] === null,
            ProposalVerificationItem::RecruitmentRule => $basis['recruitment_rule_id'] === null,
            ProposalVerificationItem::SeniorityList => $basis['entries'] === 0,
            ProposalVerificationItem::PreviousDpcReconciliation,
            ProposalVerificationItem::Representations,
            ProposalVerificationItem::OriginalDocuments => false,
            ProposalVerificationItem::RecruitmentRuleConformity => $basis['recruitment_rule_id'] === null || $basis['officers'] === 0,
        };

        if ($missing) {
            throw new WorkflowException(__("dpc.verification.items.{$item->value}.not_ready"));
        }
    }

    /**
     * Validate and normalise the previous DPC reference.
     *
     * Enforced here as well as in the form request, because this is the last point
     * before the row exists.
     *
     * @param  array{basis?: string|null, proposal_id?: int|null, reference?: string|null, held_on?: string|null, record_id?: int|null}  $input
     * @return array<string, mixed>
     */
    private function previousDpcReference(DpcProposal $proposal, array $input, ?string $remarks, ?UploadedFile $minutes): array
    {
        $basis = PreviousDpcBasis::tryFrom((string) ($input['basis'] ?? ''));

        return match ($basis) {
            PreviousDpcBasis::InSystem => (function () use ($basis, $proposal, $input): array {
                $previous = $this->previousDpcCandidates($proposal)->firstWhere('id', (int) ($input['proposal_id'] ?? 0));

                if ($previous === null) {
                    throw new WorkflowException(__('dpc.verification.previous_dpc.invalid_in_system'));
                }

                return [
                    'previous_dpc_basis' => $basis->value,
                    'previous_dpc_proposal_id' => $previous->id,
                    'previous_dpc_reference' => $previous->dpc_code,
                    'previous_dpc_held_on' => null,
                ];
            })(),
            PreviousDpcBasis::Offline => (function () use ($basis, $input, $minutes, $proposal): array {
                // Rule 1.1: a previous DPC the Commission's staff recorded stands for the upload.
                if (($input['record_id'] ?? null) !== null) {
                    $record = $this->previousDpcRecords($proposal)->firstWhere('id', (int) $input['record_id']);

                    if ($record === null) {
                        throw new WorkflowException(__('previous_dpc.verification.invalid'));
                    }

                    return [
                        'previous_dpc_basis' => $basis->value,
                        'previous_dpc_proposal_id' => null,
                        'previous_dpc_record_id' => $record->id,
                        'previous_dpc_reference' => mb_substr($record->reference, 0, 120),
                        'previous_dpc_held_on' => $record->held_on->toDateString(),
                    ];
                }

                $reference = trim((string) ($input['reference'] ?? ''));
                $heldOn = trim((string) ($input['held_on'] ?? ''));

                if ($reference === '' || $heldOn === '' || $minutes === null) {
                    throw new WorkflowException(__('dpc.verification.previous_dpc.offline_incomplete'));
                }

                return [
                    'previous_dpc_basis' => $basis->value,
                    'previous_dpc_proposal_id' => null,
                    'previous_dpc_reference' => mb_substr($reference, 0, 120),
                    'previous_dpc_held_on' => Carbon::parse($heldOn)->toDateString(),
                ];
            })(),
            PreviousDpcBasis::None => $remarks === null
                ? throw new WorkflowException(__('dpc.verification.previous_dpc.none_needs_remarks'))
                : [
                    'previous_dpc_basis' => $basis->value,
                    'previous_dpc_proposal_id' => null,
                    'previous_dpc_reference' => null,
                    'previous_dpc_held_on' => null,
                ],
            null => throw new WorkflowException(__('dpc.verification.previous_dpc.basis_required')),
        };
    }

    /**
     * Stored on the private disk; the hash is taken from the bytes that arrived, as
     * for record-gap evidence.
     *
     * @return array{disk: string, path: string, original_name: string, size_bytes: int, sha256: string}
     */
    private function storeMinutes(DpcProposal $proposal, UploadedFile $minutes): array
    {
        $disk = (string) config('dpc.documents.disk', 'local');
        $contents = (string) file_get_contents($minutes->getRealPath());

        $path = Storage::disk($disk)->putFile(sprintf('private/proposal-verifications/%d', $proposal->id), $minutes);

        if ($path === false) {
            throw new WorkflowException(__('dpc.verification.previous_dpc.store_failed'));
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr((string) $minutes->getClientOriginalName(), 0, 255),
            'size_bytes' => $minutes->getSize() ?: 0,
            'sha256' => hash('sha256', $contents),
        ];
    }

    /**
     * The list as it stands: how many officers, and a digest of who is where.
     *
     * @return array{entries: int, digest: string}
     */
    private function seniorityFingerprint(DpcProposal $proposal): array
    {
        $rows = $proposal->seniorityEntries()
            ->get(['seniority_number', 'employee_number', 'name'])
            ->map(fn (DpcSeniorityEntry $entry): string => implode('|', [
                $entry->seniority_number,
                $entry->employee_number,
                $entry->name,
            ]))
            ->all();

        return [
            'entries' => count($rows),
            'digest' => hash('sha256', implode("\n", $rows)),
        ];
    }

    /**
     * @param  array<string, mixed>  $basis
     */
    private function hash(array $basis): string
    {
        ksort($basis);

        return hash('sha256', (string) json_encode($basis));
    }

    private function assertEditable(DpcProposal $proposal): void
    {
        if (! $proposal->status->isEditable()) {
            throw new WorkflowException(__('dpc.verification.locked'));
        }
    }
}
