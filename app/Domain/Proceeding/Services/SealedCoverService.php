<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Enums\ListType;
use App\Domain\Eligibility\Enums\RemarkCode;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use App\Domain\Proceeding\Models\DpcSealedCoverOpening;
use App\Domain\PromotionOrder\Models\DpcPromotionOrder;
use App\Domain\PromotionOrder\Services\PromotionOrderService;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Opening a sealed cover (2026-11-05, BD-48 decided).
 *
 * **Who.** The DPC's own Chairman — the committee member presiding over this DPC —
 * records the competent authority's decision and opens the cover; nobody else.
 *
 * **Cleared.** The finding is acted on: a new promotion order for the officer, issued
 * by the AH through the existing order and its eSign, effective on the opening date
 * (never backdated to the DPC). The DPC's own orders are untouched.
 *
 * **Rejected.** The decision is kept with its evidence, and the seat:
 * - opened before 31 December of the DPC year — offered to the next eligible
 *   candidate on the waiting list (same category, in list order), never one already
 *   promoted, held, in a sealed cover, or offered a seat by another opening;
 * - opened after 31 December — no promotion against it through this DPC;
 * - opened on 31 December — not decided; flagged, nothing reallocated.
 *
 * Nothing is recalculated: the approved result stays as approved.
 */
class SealedCoverService
{
    public function __construct(private readonly ActivityTimelineService $timeline) {}

    /**
     * The officers of the approved result whose finding is in a sealed cover, with
     * their opening if any.
     *
     * @return list<array{result: DpcCalculationResult, opening: DpcSealedCoverOpening|null}>
     */
    public function sealed(DpcProceeding $proceeding): array
    {
        $run = $proceeding->finalCalculationRun;

        if ($run === null) {
            return [];
        }

        $openings = DpcSealedCoverOpening::query()->where('dpc_proceeding_id', $proceeding->id)->get()->keyBy('sn');
        /** @var list<array{result: DpcCalculationResult, opening: DpcSealedCoverOpening|null}> $sealed */
        $sealed = [];

        foreach (DpcCalculationResult::query()->where('dpc_calculation_run_id', $run->id)->orderBy('sn')->get() as $result) {
            if ($result->status() === RemarkCode::SealedCover) {
                $opening = $openings->get($result->sn);
                $sealed[] = ['result' => $result, 'opening' => $opening instanceof DpcSealedCoverOpening ? $opening : null];
            }
        }

        return $sealed;
    }

    /** Whether this user presides over the DPC as its Chairman. */
    public function isDpcChairman(DpcProposal $proposal, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return DpcCommitteeMember::query()
            ->where('dpc_proposal_id', $proposal->id)
            ->serving()
            ->whereNotNull('is_dpc_chairman')
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * @param  array{outcome: string, opened_on: string, authority_name: string, authority_order_number: string, authority_order_date: string, remarks?: string|null}  $data
     */
    public function open(DpcProceeding $proceeding, int $sn, array $data, UploadedFile $document, User $actor): DpcSealedCoverOpening
    {
        $proposal = $proceeding->proposal;

        if ($proposal === null || ! $this->isDpcChairman($proposal, $actor)) {
            throw new WorkflowException((string) __('dpc.sealed_cover.errors.not_chairman'));
        }

        if ($proceeding->status !== DpcProceedingStatus::ResultApproved) {
            throw new WorkflowException((string) __('dpc.sealed_cover.errors.not_approved'));
        }

        $result = collect($this->sealed($proceeding))->first(fn (array $entry): bool => (int) $entry['result']->sn === $sn);

        if ($result === null) {
            throw new WorkflowException((string) __('dpc.sealed_cover.errors.not_sealed'));
        }

        if ($result['opening'] !== null) {
            throw new WorkflowException((string) __('dpc.sealed_cover.errors.already_opened'));
        }

        $openedOn = Carbon::parse($data['opened_on'])->startOfDay();
        $outcome = $data['outcome'];
        $seat = $outcome === DpcSealedCoverOpening::REJECTED ? $this->seat($proceeding, $result['result'], $openedOn) : [null, null];

        $disk = (string) config('dpc.documents.disk', 'local');
        $path = $document->store('private/sealed-covers/'.$proposal->uuid, $disk);

        if ($path === false) {
            throw new WorkflowException((string) __('dpc.sealed_cover.errors.document'));
        }

        try {
            return DB::transaction(function () use ($proceeding, $proposal, $result, $data, $outcome, $openedOn, $seat, $disk, $path, $document, $actor): DpcSealedCoverOpening {
                [$status, $replacement] = $seat;

                $opening = DpcSealedCoverOpening::query()->create([
                    'dpc_proposal_id' => $proposal->id,
                    'dpc_proceeding_id' => $proceeding->id,
                    'dpc_calculation_result_id' => $result['result']->id,
                    'sn' => (int) $result['result']->sn,
                    'officer_name' => (string) $result['result']->officer_name,
                    'outcome' => $outcome,
                    'opened_on' => $openedOn->toDateString(),
                    'authority_name' => trim($data['authority_name']),
                    'authority_order_number' => trim($data['authority_order_number']),
                    'authority_order_date' => Carbon::parse($data['authority_order_date'])->toDateString(),
                    'remarks' => isset($data['remarks']) && trim((string) $data['remarks']) !== '' ? trim((string) $data['remarks']) : null,
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => mb_substr($document->getClientOriginalName(), 0, 255),
                    'size_bytes' => (int) $document->getSize(),
                    'sha256' => (string) hash_file('sha256', $document->getRealPath()),
                    'replacement_status' => $status,
                    'replacement_sn' => $replacement?->sn,
                    'replacement_name' => $replacement?->officer_name,
                    'opened_by' => $actor->id,
                ]);

                $this->timeline->record('dpc_proposal.sealed_cover_opened', $proposal, (string) __('dpc.sealed_cover.timeline', [
                    'officer' => $opening->officer_name,
                    'outcome' => __('dpc.sealed_cover.outcomes.'.$outcome),
                    'date' => $openedOn->format('d-m-Y'),
                ]), [
                    'opening_uuid' => $opening->uuid,
                    'proceeding_uuid' => $proceeding->uuid,
                    'sn' => $opening->sn,
                    'outcome' => $outcome,
                    'replacement_status' => $status,
                    'replacement_sn' => $opening->replacement_sn,
                    'sha256' => $opening->sha256,
                ], $actor);

                return $opening;
            });
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }

    /**
     * What a rejection does to the seat, by the opening date against 31 December of
     * the DPC year, and who is offered it.
     *
     * @return array{0: string, 1: DpcCalculationResult|null}
     */
    private function seat(DpcProceeding $proceeding, DpcCalculationResult $sealed, Carbon $openedOn): array
    {
        $proposal = $proceeding->proposal;
        $year = (int) ($proposal?->dpc_year ?: $proposal?->proposal_date?->year);
        $yearEnd = Carbon::create($year, 12, 31)->startOfDay();

        if ($openedOn->greaterThan($yearEnd)) {
            return [DpcSealedCoverOpening::REPLACEMENT_NOT_AFTER_YEAR, null];
        }

        $next = $this->nextEligible($proceeding, $sealed);

        return $next === null
            ? [DpcSealedCoverOpening::REPLACEMENT_NONE_AVAILABLE, null]
            : [DpcSealedCoverOpening::REPLACEMENT_OFFERED, $next];
    }

    /**
     * The next eligible candidate for a seat a rejection releases: the waiting list,
     * in its order, in the sealed officer's category — never anyone already
     * promoted by an order of this DPC, held, in a sealed cover, or already offered
     * a seat by another opening.
     */
    public function nextEligible(DpcProceeding $proceeding, DpcCalculationResult $sealed): ?DpcCalculationResult
    {
        $run = $proceeding->finalCalculationRun;

        if ($run === null) {
            return null;
        }

        $promoted = DpcPromotionOrder::query()->where('dpc_proposal_id', $proceeding->dpc_proposal_id)
            ->where('status', DpcPromotionOrder::STATUS_ISSUED)->get()
            ->flatMap(fn (DpcPromotionOrder $order): array => array_map(fn (array $officer): int => (int) $officer['sn'], (array) $order->officers))
            ->all();
        $held = DpcProceedingHold::query()->where('dpc_proceeding_id', $proceeding->id)->whereNull('released_at')->pluck('sn')->map(fn ($sn): int => (int) $sn)->all();
        $offered = DpcSealedCoverOpening::query()->where('dpc_proceeding_id', $proceeding->id)->whereNotNull('replacement_sn')->pluck('replacement_sn')->map(fn ($sn): int => (int) $sn)->all();

        return DpcCalculationResult::query()
            ->where('dpc_calculation_run_id', $run->id)
            ->where('list_type', ListType::Waiting->value)
            ->where('reservation_category_code', $sealed->reservation_category_code)
            ->orderBy('list_position')
            ->get()
            ->first(fn (DpcCalculationResult $result): bool => (bool) $result->is_eligible
                && $result->status() !== RemarkCode::SealedCover
                && ! app(PromotionOrderService::class)->isHeld($proceeding, $result)
                && ! in_array((int) $result->sn, [...$promoted, ...$held, ...$offered], true));
    }
}
