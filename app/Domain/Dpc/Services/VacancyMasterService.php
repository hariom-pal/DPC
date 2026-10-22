<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Enums\VacancyEntryEligibility;
use App\Domain\Dpc\Enums\VacancyEntryKind;
use App\Domain\Dpc\Enums\VacancyPositionStatus;
use App\Domain\Dpc\Enums\VacancyReason;
use App\Domain\Dpc\Enums\VacancyReconciliation;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcWorkingOfficer;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\Models\VacancyPositionEntry;
use App\Domain\Dpc\Models\VacancyPositionSanctionedStrength;
use App\Domain\Dpc\Models\VacancyPositionWorkingStrength;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The Vacancy Master: the CGPSC Administrator's year-wise vacancy position for one
 * post of one department.
 *
 * ### What it reuses rather than repeats
 *
 *  - The sanctioned strength is entered by the Administrator for each Vacancy Year
 *    (1 January – 31 December) with its sanctioning order and PDF. It is NOT read
 *    from the Department Post Mapping: a sanctioned strength can change from one year
 *    to the next on its own order. The mapping still identifies the post in force on
 *    1 January and leads to its Recruitment Rule.
 *  - The Vacancy Year window, the Recruitment Rule lookup, the rounding and the
 *    promotion / direct quota arithmetic are `VacancyEngine`'s
 *    (`periodForYear`, `recruitmentRuleFor`, `quotaPosition`). There is one
 *    quota calculation in the application.
 *  - Documents follow the record-gap discipline: private disk, hash of the bytes
 *    that arrived, streamed only to an authorised user.
 *
 * ### What it will not do
 *
 * It does not decide that a reason counts when no rule says so, and it does not
 * guess whether a count-only entry is already inside the opening vacancy — the
 * Administrator answers that for every anticipated entry (`VacancyReconciliation`).
 */
class VacancyMasterService
{
    public function __construct(
        private readonly VacancyEngine $engine,
        private readonly ActivityTimelineService $timeline,
    ) {}

    // ------------------------------------------------------------------ reading

    /**
     * Every post of the department, as it stood on 1 January of the year, with its
     * current position (if any) and that position's figures.
     *
     * @return list<array{name: string, mapping: DepartmentPost|null, any: DepartmentPost, position: VacancyPosition|null, breakdown: array<string, mixed>|null}>
     */
    public function postsFor(Department $department, int $year): array
    {
        $chains = DepartmentPost::query()
            ->where('department_id', $department->id)
            ->orderBy('name')
            ->orderByDesc('id')
            ->get()
            ->groupBy('name');

        $positions = VacancyPosition::query()
            ->where('department_id', $department->id)
            ->where('vacancy_year', $year)
            ->with('sanctionedStrength', 'workingStrength', 'entries')
            ->get()
            ->groupBy('post_name')
            ->map(fn (Collection $revisions): VacancyPosition => $revisions->sortByDesc('revision')->first());

        $rows = [];

        foreach ($chains as $name => $mappings) {
            $position = $positions->get((string) $name);

            $rows[] = [
                'name' => (string) $name,
                'mapping' => $this->mappingInForce($mappings, $year),
                'any' => $mappings->first(),
                'position' => $position,
                'breakdown' => $position === null ? null : $this->breakdown($position),
            ];
        }

        return $rows;
    }

    /**
     * The mapping row in force on 1 January of `$year` among one post's rows.
     *
     * @param  Collection<int, DepartmentPost>  $mappings
     */
    public function mappingInForce(Collection $mappings, int $year): ?DepartmentPost
    {
        [$from] = $this->engine->periodForYear($year);

        return $mappings
            ->filter(fn (DepartmentPost $post): bool => ($post->effective_from === null || $post->effective_from->lte($from))
                && ($post->effective_to === null || $post->effective_to->gte($from)))
            ->sortByDesc(fn (DepartmentPost $post): string => $post->effective_from?->toDateString() ?? '0000-00-00')
            ->first();
    }

    /**
     * The latest revision of a post's position for the year, or null.
     */
    public function current(int $departmentId, string $postName, int $year): ?VacancyPosition
    {
        return VacancyPosition::query()
            ->where('department_id', $departmentId)
            ->where('post_name', $postName)
            ->where('vacancy_year', $year)
            ->orderByDesc('revision')
            ->first();
    }

    /**
     * The finalized position in force — the only one a DPC proposal may rely on.
     */
    public function finalized(int $departmentId, string $postName, int $year): ?VacancyPosition
    {
        return VacancyPosition::query()
            ->where('department_id', $departmentId)
            ->where('post_name', $postName)
            ->where('vacancy_year', $year)
            ->where('status', VacancyPositionStatus::Finalized->value)
            ->orderByDesc('revision')
            ->first();
    }

    /**
     * The finalized position for a DPC proposal's department, Promotion To Post
     * and DPC Year.
     */
    public function finalizedForProposal(DpcProposal $proposal): ?VacancyPosition
    {
        $post = $proposal->promotionToPost;
        $year = $proposal->dpc_year ?: $proposal->proposal_date->year;

        if ($post === null) {
            return null;
        }

        return $this->finalized((int) $proposal->department_id, $post->name, (int) $year);
    }

    /**
     * Every revision of a post's position for the year, newest first.
     *
     * @return Collection<int, VacancyPosition>
     */
    public function revisions(int $departmentId, string $postName, int $year): Collection
    {
        return VacancyPosition::query()
            ->where('department_id', $departmentId)
            ->where('post_name', $postName)
            ->where('vacancy_year', $year)
            ->with('finalizer')
            ->orderByDesc('revision')
            ->get();
    }

    /**
     * The 1 April seniority reference date for a Vacancy Year — rule 1.6, "as on
     * 1 April of the preceding year".
     */
    public function seniorityReferenceDate(int $year): Carbon
    {
        return Carbon::create($year - 1, 4, 1)->startOfDay();
    }

    // ------------------------------------------------------------ calculation

    /**
     * Every figure of the position, how each was reached, and what still stops it
     * from being finalized.
     *
     * A finalized or superseded position answers from its frozen snapshot, so a
     * later change to a mapping or a rule never alters it.
     *
     * @return array<string, mixed>
     */
    public function breakdown(VacancyPosition $position): array
    {
        if (! $position->isDraft() && is_array($position->breakdown)) {
            return $position->breakdown;
        }

        $position->loadMissing('sanctionedStrength', 'workingStrength', 'entries');

        [$from, $to] = $this->engine->periodForYear($position->vacancy_year);
        $mapping = $this->mappingFor($position);
        $rule = $this->ruleFor($position, $from);
        // Entered for this Vacancy Year — never the Post Mapping's figure.
        $sanctionedRecord = $position->sanctionedStrength;
        $sanctioned = $sanctionedRecord?->sanctioned_total;
        $percentage = $rule?->promotion_recruitment_pct;
        $working = $position->workingStrength;

        $active = $position->entries->filter(fn (VacancyPositionEntry $entry): bool => $entry->isActive());
        $existing = $active->filter(fn (VacancyPositionEntry $entry): bool => $entry->kind === VacancyEntryKind::Existing);
        $anticipated = $active->filter(fn (VacancyPositionEntry $entry): bool => $entry->kind === VacancyEntryKind::Anticipated);
        $counted = $anticipated->filter(fn (VacancyPositionEntry $entry): bool => $entry->isCounted());

        $countedPromotion = (int) $counted->where('occupant_quota', DpcWorkingOfficer::SOURCE_PROMOTION)->sum('vacancy_count');
        $countedDirect = (int) $counted->where('occupant_quota', DpcWorkingOfficer::SOURCE_DIRECT)->sum('vacancy_count');
        $pending = $anticipated->filter(fn (VacancyPositionEntry $entry): bool => $entry->eligibility === VacancyEntryEligibility::PendingVerification);
        $alreadyInOpening = $anticipated->filter(fn (VacancyPositionEntry $entry): bool => $entry->eligibility === VacancyEntryEligibility::Eligible
            && $entry->reconciliation === VacancyReconciliation::AlreadyInOpening);
        $notEligible = $anticipated->filter(fn (VacancyPositionEntry $entry): bool => $entry->eligibility === VacancyEntryEligibility::NotEligible);

        $opening = $sanctioned === null || $working === null ? null : $sanctioned - $working->working_total;
        $split = $working !== null && $working->hasQuotaSplit();

        $openingQuota = $split
            ? $this->engine->quotaPosition($sanctioned, $percentage === null ? null : (string) $percentage, (int) $working->working_promotion, (int) $working->working_direct)
            : null;

        $finalQuota = $split
            ? $this->engine->quotaPosition(
                $sanctioned,
                $percentage === null ? null : (string) $percentage,
                (int) $working->working_promotion - $countedPromotion,
                (int) $working->working_direct - $countedDirect,
            )
            : null;

        $issues = [];

        if ($mapping === null) {
            $issues[] = ['key' => 'no_mapping', 'params' => ['date' => $from->format('d-m-Y')]];
        }

        if ($sanctionedRecord === null) {
            $issues[] = ['key' => 'sanctioned_not_entered', 'params' => ['year' => $position->vacancy_year]];
        }

        if ($working === null) {
            $issues[] = ['key' => 'working_not_entered', 'params' => []];
        } elseif (! $split) {
            $issues[] = ['key' => 'working_split_missing', 'params' => []];
        }

        // The sanctioned strength may be entered, or corrected, after the working
        // strength: a working figure above it must still be confirmed explicitly.
        if ($working !== null && $sanctioned !== null && $working->working_total > $sanctioned
            && ! $working->exceeds_sanctioned_acknowledged) {
            $issues[] = ['key' => 'working_exceeds_sanctioned', 'params' => [
                'working' => $working->working_total,
                'sanctioned' => $sanctioned,
            ]];
        }

        if ($rule === null) {
            $issues[] = ['key' => 'no_rule', 'params' => ['date' => $from->format('d-m-Y')]];
        }

        if ($pending->isNotEmpty()) {
            $issues[] = ['key' => 'pending_entries', 'params' => ['count' => $pending->count()]];
        }

        if ($opening !== null && (int) $existing->sum('vacancy_count') > max(0, $opening)) {
            $issues[] = ['key' => 'existing_exceed_opening', 'params' => [
                'existing' => (int) $existing->sum('vacancy_count'),
                'opening' => max(0, $opening),
            ]];
        }

        if ($split) {
            if ($countedPromotion > (int) $working->working_promotion) {
                $issues[] = ['key' => 'anticipated_exceed_working', 'params' => [
                    'quota' => __('vacancymaster.quota.promotion'),
                    'counted' => $countedPromotion,
                    'working' => (int) $working->working_promotion,
                ]];
            }

            if ($countedDirect > (int) $working->working_direct) {
                $issues[] = ['key' => 'anticipated_exceed_working', 'params' => [
                    'quota' => __('vacancymaster.quota.direct'),
                    'counted' => $countedDirect,
                    'working' => (int) $working->working_direct,
                ]];
            }
        }

        if ($finalQuota !== null
            && (($finalQuota['promotion_vacancy'] ?? 0) < 0 || ($finalQuota['direct_vacancy'] ?? 0) < 0)) {
            $issues[] = ['key' => 'quota_overfilled', 'params' => []];
        }

        return [
            'vacancy_year' => $position->vacancy_year,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'department_post_id' => $mapping?->id,
            'sanctioned' => $sanctioned,
            'sanctioned_order' => $sanctionedRecord === null ? null : [
                'order_number' => $sanctionedRecord->order_number,
                'order_date' => $sanctionedRecord->order_date->toDateString(),
                'document_sha256' => $sanctionedRecord->sha256,
            ],
            'recruitment_rule_id' => $rule?->id,
            'recruitment_rule_version' => $rule?->version,
            'promotion_percentage' => $percentage === null ? null : (string) $percentage,
            'rounding' => $this->engine->rounding(),
            'working' => $working === null ? null : [
                'total' => $working->working_total,
                'promotion' => $working->working_promotion,
                'direct' => $working->working_direct,
                'deputation' => $working->working_deputation,
                'order_number' => $working->order_number,
                'order_date' => $working->order_date->toDateString(),
                'document_sha256' => $working->sha256,
            ],
            'opening_vacancy' => $opening,
            'opening_quota' => $openingQuota,
            'existing_entries_total' => (int) $existing->sum('vacancy_count'),
            'anticipated_counted_promotion' => $countedPromotion,
            'anticipated_counted_direct' => $countedDirect,
            'anticipated_already_in_opening' => (int) $alreadyInOpening->sum('vacancy_count'),
            'anticipated_pending' => (int) $pending->sum('vacancy_count'),
            'anticipated_not_eligible' => (int) $notEligible->sum('vacancy_count'),
            'final_quota' => $finalQuota,
            'final_total' => $finalQuota === null || $finalQuota['promotion_vacancy'] === null
                ? null
                : $finalQuota['promotion_vacancy'] + $finalQuota['direct_vacancy'],
            'entries' => $active->map(fn (VacancyPositionEntry $entry): array => [
                'uuid' => $entry->uuid,
                'kind' => $entry->kind->value,
                'reason' => $entry->reason->value,
                'quota' => $entry->occupant_quota,
                'count' => $entry->vacancy_count,
                'effective_date' => $entry->effective_date->toDateString(),
                'eligibility' => $entry->eligibility->value,
                'reconciliation' => $entry->reconciliation?->value,
                'counted' => $entry->isCounted(),
                'order_number' => $entry->order_number,
                'document_sha256' => $entry->sha256,
            ])->values()->all(),
            'issues' => $issues,
            'complete' => $issues === [],
        ];
    }

    /**
     * A one-word state for the department listing.
     *
     * @param  array<string, mixed>|null  $breakdown
     */
    public function statusKey(?VacancyPosition $position, ?array $breakdown): string
    {
        if ($position === null) {
            return 'not_started';
        }

        if (! $position->isDraft()) {
            return $position->status->value;
        }

        if ($breakdown !== null && $breakdown['sanctioned'] === null) {
            return 'sanctioned_not_entered';
        }

        if ($breakdown !== null && $breakdown['working'] === null) {
            return 'working_not_entered';
        }

        return ($breakdown['complete'] ?? false) ? 'ready' : 'incomplete';
    }

    // ---------------------------------------------------------------- writing

    /**
     * Open the draft for a post and year, or return the draft already open.
     *
     * @throws WorkflowException when a finalized position exists (correct it by revision)
     */
    public function openDraft(Department $department, DepartmentPost $post, int $year, User $actor): VacancyPosition
    {
        $this->assertYear($year);

        if ($post->department_id !== $department->id) {
            throw new WorkflowException(__('vacancymaster.errors.post_not_in_department'));
        }

        return DB::transaction(function () use ($department, $post, $year, $actor): VacancyPosition {
            $current = $this->current($department->id, $post->name, $year);

            if ($current !== null && $current->isDraft()) {
                return $current;
            }

            if ($current !== null) {
                throw new WorkflowException(__('vacancymaster.errors.already_finalized'));
            }

            [$from, $to] = $this->engine->periodForYear($year);
            $mapping = $this->mappingInForce($this->chain($department->id, $post->name), $year);

            $position = VacancyPosition::query()->create([
                'department_id' => $department->id,
                'post_name' => $post->name,
                'department_post_id' => $mapping?->id,
                'vacancy_year' => $year,
                'revision' => 1,
                'status' => VacancyPositionStatus::Draft->value,
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
                'created_by' => $actor->id,
            ]);

            $this->record('vacancy_position.draft_opened', $position, __('vacancymaster.timeline.draft_opened', [
                'post' => $position->post_name,
                'year' => $year,
            ]), [], $actor);

            return $position;
        });
    }

    /**
     * Record (or correct) the sanctioned strength for the Vacancy Year.
     *
     * Entered year by year with the sanctioning order and its PDF. A second save is a
     * correction: it needs a reason, and the earlier figure stays in the history.
     *
     * @param  array{sanctioned_total: int, order_number: string, order_date: string, remarks?: string|null, correction_reason?: string|null}  $data
     */
    public function saveSanctionedStrength(VacancyPosition $position, array $data, ?UploadedFile $document, User $actor): VacancyPositionSanctionedStrength
    {
        $this->assertDraft($position);

        if ($document === null) {
            throw new WorkflowException(__('vacancymaster.errors.document_required'), 'document');
        }

        $total = (int) $data['sanctioned_total'];
        $previous = $position->sanctionedStrength;
        $reason = trim((string) ($data['correction_reason'] ?? ''));

        if ($previous !== null && $reason === '') {
            throw new WorkflowException(__('vacancymaster.errors.correction_reason_required'), 'correction_reason');
        }

        return DB::transaction(function () use ($position, $data, $document, $actor, $total, $previous, $reason): VacancyPositionSanctionedStrength {
            $stored = $this->store($document, $position, 'sanctioned-strength');

            $row = VacancyPositionSanctionedStrength::query()->create([
                'vacancy_position_id' => $position->id,
                'sanctioned_total' => $total,
                'order_number' => trim((string) $data['order_number']),
                'order_date' => Carbon::parse((string) $data['order_date'])->toDateString(),
                ...$stored,
                'remarks' => $this->clean($data['remarks'] ?? null),
                'correction_reason' => $previous === null ? null : $reason,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);

            $position->forceFill(['sanctioned_strength_id' => $row->id])->save();
            $position->unsetRelation('sanctionedStrength');

            $this->record(
                $previous === null ? 'vacancy_position.sanctioned_strength_recorded' : 'vacancy_position.sanctioned_strength_corrected',
                $position,
                __($previous === null ? 'vacancymaster.timeline.sanctioned_recorded' : 'vacancymaster.timeline.sanctioned_corrected', [
                    'post' => $position->post_name,
                    'year' => $position->vacancy_year,
                    'from' => $previous->sanctioned_total ?? '—',
                    'to' => $total,
                ]),
                [
                    'previous' => $previous?->only(['sanctioned_total', 'order_number']),
                    'sanctioned_total' => $total,
                    'order_number' => $row->order_number,
                    'document_sha256' => $row->sha256,
                    'correction_reason' => $row->correction_reason,
                ],
                $actor,
            );

            return $row;
        });
    }

    /**
     * Record (or correct) the working strength as on 1 January.
     *
     * @param  array{working_total: int, working_promotion?: int|null, working_direct?: int|null, working_deputation?: int|null, exceeds_sanctioned_acknowledged?: bool, order_number: string, order_date: string, remarks?: string|null, correction_reason?: string|null}  $data
     */
    public function saveWorkingStrength(VacancyPosition $position, array $data, ?UploadedFile $document, User $actor): VacancyPositionWorkingStrength
    {
        $this->assertDraft($position);

        if ($document === null) {
            throw new WorkflowException(__('vacancymaster.errors.document_required'), 'document');
        }

        $total = (int) $data['working_total'];
        $promotion = $data['working_promotion'] ?? null;
        $direct = $data['working_direct'] ?? null;
        $deputation = $data['working_deputation'] ?? null;
        $split = [$promotion, $direct, $deputation];

        if (in_array(null, $split, true) && array_filter($split, fn ($v): bool => $v !== null) !== []) {
            throw new WorkflowException(__('vacancymaster.errors.split_all_or_none'), 'working_split');
        }

        if (! in_array(null, $split, true) && (int) $promotion + (int) $direct + (int) $deputation !== $total) {
            throw new WorkflowException(__('vacancymaster.errors.split_must_sum', ['total' => $total]), 'working_split');
        }

        $sanctioned = $position->sanctionedStrength?->sanctioned_total;
        $acknowledged = (bool) ($data['exceeds_sanctioned_acknowledged'] ?? false);

        if ($sanctioned !== null && $total > $sanctioned && (! $acknowledged || trim((string) ($data['remarks'] ?? '')) === '')) {
            throw new WorkflowException(__('vacancymaster.errors.exceeds_sanctioned', [
                'working' => $total,
                'sanctioned' => $sanctioned,
            ]), 'exceeds_sanctioned_acknowledged');
        }

        $previous = $position->workingStrength;
        $reason = trim((string) ($data['correction_reason'] ?? ''));

        if ($previous !== null && $reason === '') {
            throw new WorkflowException(__('vacancymaster.errors.correction_reason_required'), 'correction_reason');
        }

        return DB::transaction(function () use ($position, $data, $document, $actor, $total, $promotion, $direct, $deputation, $acknowledged, $previous, $reason): VacancyPositionWorkingStrength {
            $stored = $this->store($document, $position, 'working-strength');

            $row = VacancyPositionWorkingStrength::query()->create([
                'vacancy_position_id' => $position->id,
                'working_total' => $total,
                'working_promotion' => $promotion === null ? null : (int) $promotion,
                'working_direct' => $direct === null ? null : (int) $direct,
                'working_deputation' => $deputation === null ? null : (int) $deputation,
                'exceeds_sanctioned_acknowledged' => $acknowledged,
                'order_number' => trim((string) $data['order_number']),
                'order_date' => Carbon::parse((string) $data['order_date'])->toDateString(),
                ...$stored,
                'remarks' => $this->clean($data['remarks'] ?? null),
                'correction_reason' => $previous === null ? null : $reason,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);

            $position->forceFill(['working_strength_id' => $row->id])->save();

            $this->record(
                $previous === null ? 'vacancy_position.working_strength_recorded' : 'vacancy_position.working_strength_corrected',
                $position,
                __($previous === null ? 'vacancymaster.timeline.working_recorded' : 'vacancymaster.timeline.working_corrected', [
                    'post' => $position->post_name,
                    'year' => $position->vacancy_year,
                    'from' => $previous->working_total ?? '—',
                    'to' => $total,
                ]),
                [
                    'previous' => $previous?->only(['working_total', 'working_promotion', 'working_direct', 'working_deputation', 'order_number']),
                    'working_total' => $total,
                    'order_number' => $row->order_number,
                    'document_sha256' => $row->sha256,
                    'correction_reason' => $row->correction_reason,
                ],
                $actor,
            );

            return $row;
        });
    }

    /**
     * Add one reason-wise vacancy entry.
     *
     * @param  array<string, mixed>  $data
     */
    public function addEntry(VacancyPosition $position, array $data, ?UploadedFile $document, User $actor): VacancyPositionEntry
    {
        $this->assertDraft($position);

        if ($document === null) {
            throw new WorkflowException(__('vacancymaster.errors.document_required'), 'document');
        }

        $kind = VacancyEntryKind::from((string) $data['kind']);
        $reason = VacancyReason::from((string) $data['reason']);
        $effective = Carbon::parse((string) $data['effective_date'])->startOfDay();
        [$from, $to] = $this->engine->periodForYear($position->vacancy_year);

        // The Vacancy Year boundaries, both inclusive.
        if ($kind === VacancyEntryKind::Anticipated && ! $effective->betweenIncluded($from->copy()->startOfDay(), $to)) {
            throw new WorkflowException(__('vacancymaster.errors.anticipated_outside_year', [
                'from' => $from->format('d-m-Y'),
                'to' => $to->format('d-m-Y'),
            ]), 'effective_date');
        }

        if ($kind === VacancyEntryKind::Existing && $effective->gt($from)) {
            throw new WorkflowException(__('vacancymaster.errors.existing_after_opening', ['date' => $from->format('d-m-Y')]), 'effective_date');
        }

        $quota = $data['occupant_quota'] ?? null;
        $reconciliation = isset($data['reconciliation']) && $data['reconciliation'] !== ''
            ? VacancyReconciliation::from((string) $data['reconciliation'])
            : null;

        if ($kind === VacancyEntryKind::Anticipated && ($quota === null || $reconciliation === null)) {
            throw new WorkflowException(__('vacancymaster.errors.anticipated_needs_quota_and_reconciliation'), 'occupant_quota');
        }

        $higher = $this->higherPostReference($position, $reason, $data);

        $this->assertNotDuplicate($position, $kind, $reason, $effective, (string) $data['order_number'], $quota, $higher['higher_dpc_reference'] ?? null);

        [$eligibility, $note] = $this->eligibilityFor($kind, $reason, $effective, $data);

        return DB::transaction(function () use ($position, $data, $document, $actor, $kind, $reason, $effective, $quota, $reconciliation, $higher, $eligibility, $note): VacancyPositionEntry {
            $stored = $this->store($document, $position, 'entries');

            $entry = VacancyPositionEntry::query()->create([
                'vacancy_position_id' => $position->id,
                'kind' => $kind->value,
                'reason' => $reason->value,
                'occupant_quota' => $kind === VacancyEntryKind::Anticipated ? $quota : null,
                'vacancy_count' => (int) $data['vacancy_count'],
                'effective_date' => $effective->toDateString(),
                'order_number' => trim((string) $data['order_number']),
                'order_date' => Carbon::parse((string) $data['order_date'])->toDateString(),
                ...$stored,
                'remarks' => $this->clean($data['remarks'] ?? null),
                'reconciliation' => $kind === VacancyEntryKind::Anticipated ? $reconciliation?->value : null,
                ...$higher,
                'deputation_to' => $reason === VacancyReason::Deputation && ! empty($data['deputation_to'])
                    ? Carbon::parse((string) $data['deputation_to'])->toDateString() : null,
                'deputation_expected_months' => $reason === VacancyReason::Deputation && ! empty($data['deputation_expected_months'])
                    ? (int) $data['deputation_expected_months'] : null,
                'against_sanctioned_post' => $reason === VacancyReason::Deputation && isset($data['against_sanctioned_post']) && $data['against_sanctioned_post'] !== ''
                    ? (bool) (int) $data['against_sanctioned_post'] : null,
                'cadre_details' => $reason === VacancyReason::Deputation ? $this->clean($data['cadre_details'] ?? null) : null,
                'eligibility' => $eligibility->value,
                'eligibility_note' => $note,
                'status' => VacancyPositionEntry::STATUS_ACTIVE,
                'recorded_by' => $actor->id,
            ]);

            $this->record('vacancy_position.entry_added', $position, __('vacancymaster.timeline.entry_added', [
                'count' => $entry->vacancy_count,
                'reason' => $reason->label(),
                'kind' => $kind->label(),
                'eligibility' => $eligibility->label(),
            ]), [
                'entry' => $entry->uuid,
                'kind' => $kind->value,
                'reason' => $reason->value,
                'count' => $entry->vacancy_count,
                'eligibility' => $eligibility->value,
                'document_sha256' => $entry->sha256,
            ], $actor);

            return $entry;
        });
    }

    /**
     * Resolve a pending entry: eligible on a stated basis, or not eligible.
     *
     * @param  array{decision: string, basis?: string|null, remarks: string, promotion_order_number?: string|null, promotion_order_date?: string|null}  $data
     */
    public function verifyEntry(VacancyPositionEntry $entry, array $data, User $actor): VacancyPositionEntry
    {
        $position = $entry->position;
        $this->assertDraft($position);

        if (! $entry->isActive() || $entry->eligibility !== VacancyEntryEligibility::PendingVerification) {
            throw new WorkflowException(__('vacancymaster.errors.not_pending'));
        }

        $remarks = trim($data['remarks']);

        if ($remarks === '') {
            throw new WorkflowException(__('vacancymaster.errors.verification_remarks_required'), 'remarks');
        }

        $decision = (string) $data['decision'];
        $basis = $data['basis'] ?? null;
        $updates = [];

        if ($decision === VacancyEntryEligibility::Eligible->value) {
            if (! in_array($basis, $entry->reason->verificationBases(), true)) {
                throw new WorkflowException(__('vacancymaster.errors.basis_required'), 'basis');
            }

            if ($basis === 'promotion_order_issued') {
                $number = trim((string) ($data['promotion_order_number'] ?? $entry->promotion_order_number ?? ''));
                $date = $data['promotion_order_date'] ?? $entry->promotion_order_date?->toDateString();

                if ($number === '' || empty($date)) {
                    throw new WorkflowException(__('vacancymaster.errors.promotion_order_required'), 'promotion_order_number');
                }

                $updates = ['promotion_order_number' => $number, 'promotion_order_date' => Carbon::parse((string) $date)->toDateString()];
            }

            if ($basis === 'higher_dpc_received_validated' && $entry->higherDpcProposal !== null
                && in_array($entry->higherDpcProposal->status, [DpcProposalStatus::Draft, DpcProposalStatus::Returned, DpcProposalStatus::Cancelled], true)) {
                throw new WorkflowException(__('vacancymaster.errors.higher_dpc_not_received', [
                    'code' => $entry->higherDpcProposal->dpc_code,
                    'status' => $entry->higherDpcProposal->status->value,
                ]), 'basis');
            }
        } elseif ($decision !== VacancyEntryEligibility::NotEligible->value) {
            throw new WorkflowException(__('vacancymaster.errors.decision_required'), 'decision');
        }

        return DB::transaction(function () use ($entry, $position, $decision, $basis, $remarks, $updates, $actor): VacancyPositionEntry {
            $entry->forceFill([
                ...$updates,
                'eligibility' => $decision,
                'verification_basis' => $decision === VacancyEntryEligibility::Eligible->value ? $basis : null,
                'verification_remarks' => $remarks,
                'verified_by' => $actor->id,
                'verified_at' => now(),
            ])->save();

            $this->record('vacancy_position.entry_verified', $position, __('vacancymaster.timeline.entry_verified', [
                'reason' => $entry->reason->label(),
                'decision' => VacancyEntryEligibility::from($decision)->label(),
            ]), ['entry' => $entry->uuid, 'decision' => $decision, 'basis' => $basis, 'remarks' => $remarks], $actor);

            return $entry;
        });
    }

    /**
     * Withdraw an entry. It is kept, marked withdrawn, with who and why.
     */
    public function withdrawEntry(VacancyPositionEntry $entry, string $reason, User $actor): void
    {
        $position = $entry->position;
        $this->assertDraft($position);

        if (! $entry->isActive()) {
            throw new WorkflowException(__('vacancymaster.errors.already_withdrawn'));
        }

        if (trim($reason) === '') {
            throw new WorkflowException(__('vacancymaster.errors.withdrawal_reason_required'), 'withdrawal_reason');
        }

        DB::transaction(function () use ($entry, $position, $reason, $actor): void {
            $entry->forceFill([
                'status' => VacancyPositionEntry::STATUS_WITHDRAWN,
                'withdrawal_reason' => trim($reason),
                'withdrawn_by' => $actor->id,
                'withdrawn_at' => now(),
            ])->save();

            $this->record('vacancy_position.entry_withdrawn', $position, __('vacancymaster.timeline.entry_withdrawn', [
                'reason' => $entry->reason->label(),
                'count' => $entry->vacancy_count,
            ]), ['entry' => $entry->uuid, 'withdrawal_reason' => trim($reason)], $actor);
        });
    }

    /**
     * Finalize: freeze every figure, the mapping, the rule and the breakdown.
     *
     * @throws WorkflowException listing what is still unresolved
     */
    public function finalize(VacancyPosition $position, User $actor): VacancyPosition
    {
        $this->assertDraft($position);

        $breakdown = $this->breakdown($position->fresh() ?? $position);

        if (! $breakdown['complete']) {
            throw new WorkflowException(__('vacancymaster.errors.cannot_finalize', [
                'issues' => implode(' ', array_map(
                    fn (array $issue): string => __('vacancymaster.issues.'.$issue['key'], $issue['params']),
                    $breakdown['issues'],
                )),
            ]));
        }

        return DB::transaction(function () use ($position, $breakdown, $actor): VacancyPosition {
            $locked = VacancyPosition::query()->whereKey($position->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($locked);

            VacancyPosition::query()
                ->where('department_id', $locked->department_id)
                ->where('post_name', $locked->post_name)
                ->where('vacancy_year', $locked->vacancy_year)
                ->where('status', VacancyPositionStatus::Finalized->value)
                ->update(['status' => VacancyPositionStatus::Superseded->value, 'updated_at' => now()]);

            $locked->forceFill([
                'status' => VacancyPositionStatus::Finalized->value,
                'department_post_id' => $breakdown['department_post_id'],
                'sanctioned_strength' => $breakdown['sanctioned'],
                'recruitment_rule_id' => $breakdown['recruitment_rule_id'],
                'promotion_percentage' => $breakdown['promotion_percentage'],
                'rounding_rule' => $breakdown['rounding'],
                'opening_vacancy' => $breakdown['opening_vacancy'],
                'promotion_vacancy' => $breakdown['final_quota']['promotion_vacancy'],
                'direct_vacancy' => $breakdown['final_quota']['direct_vacancy'],
                'breakdown' => $breakdown,
                'finalized_by' => $actor->id,
                'finalized_at' => now(),
            ])->save();

            $this->record('vacancy_position.finalized', $locked, __('vacancymaster.timeline.finalized', [
                'post' => $locked->post_name,
                'year' => $locked->vacancy_year,
                'revision' => $locked->revision,
                'promotion' => $locked->promotion_vacancy,
                'direct' => $locked->direct_vacancy,
            ]), ['breakdown' => $breakdown], $actor);

            return $locked;
        });
    }

    /**
     * Correct a finalized position: a new draft revision carrying the working
     * strength and active entries forward. The finalized revision is not touched
     * until the new one is itself finalized, when it becomes superseded.
     */
    public function revise(VacancyPosition $position, string $reason, User $actor): VacancyPosition
    {
        if (trim($reason) === '') {
            throw new WorkflowException(__('vacancymaster.errors.revision_reason_required'), 'revision_reason');
        }

        $current = $this->current($position->department_id, $position->post_name, $position->vacancy_year);

        if ($current === null || $current->id !== $position->id || $position->status !== VacancyPositionStatus::Finalized) {
            throw new WorkflowException(__('vacancymaster.errors.revise_only_latest_finalized'));
        }

        return DB::transaction(function () use ($position, $reason, $actor): VacancyPosition {
            $position->loadMissing('sanctionedStrength', 'workingStrength', 'entries');

            $draft = VacancyPosition::query()->create([
                'department_id' => $position->department_id,
                'post_name' => $position->post_name,
                'department_post_id' => $position->department_post_id,
                'vacancy_year' => $position->vacancy_year,
                'revision' => $position->revision + 1,
                'status' => VacancyPositionStatus::Draft->value,
                'supersedes_id' => $position->id,
                'revision_reason' => trim($reason),
                'period_from' => $position->period_from->toDateString(),
                'period_to' => $position->period_to->toDateString(),
                'created_by' => $actor->id,
            ]);

            if ($position->sanctionedStrength !== null) {
                $copy = $position->sanctionedStrength->replicate(['uuid']);
                $copy->vacancy_position_id = $draft->id;
                $copy->correction_reason = __('vacancymaster.carried_from_revision', ['revision' => $position->revision]);
                $copy->save();
                $draft->forceFill(['sanctioned_strength_id' => $copy->id])->save();
            }

            if ($position->workingStrength !== null) {
                $copy = $position->workingStrength->replicate(['uuid']);
                $copy->vacancy_position_id = $draft->id;
                $copy->correction_reason = __('vacancymaster.carried_from_revision', ['revision' => $position->revision]);
                $copy->save();
                $draft->forceFill(['working_strength_id' => $copy->id])->save();
            }

            foreach ($position->entries->filter(fn (VacancyPositionEntry $entry): bool => $entry->isActive()) as $entry) {
                $copy = $entry->replicate(['uuid']);
                $copy->vacancy_position_id = $draft->id;
                $copy->save();
            }

            $this->record('vacancy_position.revision_opened', $draft, __('vacancymaster.timeline.revision_opened', [
                'revision' => $draft->revision,
                'previous' => $position->revision,
            ]), ['supersedes' => $position->uuid, 'reason' => trim($reason)], $actor);

            return $draft;
        });
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @return Collection<int, DepartmentPost>
     */
    private function chain(int $departmentId, string $postName): Collection
    {
        return DepartmentPost::query()
            ->where('department_id', $departmentId)
            ->where('name', $postName)
            ->get();
    }

    private function mappingFor(VacancyPosition $position): ?DepartmentPost
    {
        return $this->mappingInForce($this->chain($position->department_id, $position->post_name), $position->vacancy_year);
    }

    private function ruleFor(VacancyPosition $position, Carbon $on): ?RecruitmentRule
    {
        $ids = $this->chain($position->department_id, $position->post_name)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return $ids === [] ? null : $this->engine->recruitmentRuleFor($ids, $on);
    }

    /**
     * The eligibility an entry starts with, and why.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: VacancyEntryEligibility, 1: string|null}
     */
    private function eligibilityFor(VacancyEntryKind $kind, VacancyReason $reason, Carbon $effective, array $data): array
    {
        if ($kind === VacancyEntryKind::Existing) {
            return [VacancyEntryEligibility::Informational, __('vacancymaster.notes.existing')];
        }

        return match ($reason) {
            VacancyReason::Retirement => [VacancyEntryEligibility::Eligible, __('vacancymaster.notes.retirement')],
            VacancyReason::HigherPostPromotion => [VacancyEntryEligibility::PendingVerification, __('vacancymaster.notes.higher_post')],
            VacancyReason::Death, VacancyReason::Other => [VacancyEntryEligibility::PendingVerification, __('vacancymaster.notes.unruled')],
            VacancyReason::Deputation => $this->deputationEligibility($effective, $data),
        };
    }

    /**
     * Rule 2.3: a post vacated by deputation against a sanctioned deputation post
     * counts when the vacancy lasts more than one year. A deputation without a
     * sanctioned setup is the rule's second sentence, which is left to the
     * Administrator to confirm.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: VacancyEntryEligibility, 1: string}
     */
    private function deputationEligibility(Carbon $start, array $data): array
    {
        $against = isset($data['against_sanctioned_post']) && $data['against_sanctioned_post'] !== ''
            ? (bool) (int) $data['against_sanctioned_post']
            : null;

        $months = null;

        if (! empty($data['deputation_to'])) {
            $end = Carbon::parse((string) $data['deputation_to'])->startOfDay();
            $months = $end->gt($start->copy()->addYear()) ? 13 : 12;
        } elseif (! empty($data['deputation_expected_months'])) {
            $months = (int) $data['deputation_expected_months'];
        }

        if ($against === false) {
            return [VacancyEntryEligibility::PendingVerification, __('vacancymaster.notes.deputation_no_setup')];
        }

        if ($against === null || $months === null) {
            return [VacancyEntryEligibility::PendingVerification, __('vacancymaster.notes.deputation_undetermined')];
        }

        return $months > 12
            ? [VacancyEntryEligibility::Eligible, __('vacancymaster.notes.deputation_over_a_year')]
            : [VacancyEntryEligibility::NotEligible, __('vacancymaster.notes.deputation_within_a_year')];
    }

    /**
     * The higher post and DPC cited by a higher-post promotion entry.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function higherPostReference(VacancyPosition $position, VacancyReason $reason, array $data): array
    {
        if ($reason !== VacancyReason::HigherPostPromotion) {
            return [];
        }

        $higher = DepartmentPost::query()->find((int) ($data['higher_post_id'] ?? 0));

        if ($higher === null || $higher->department_id !== $position->department_id || $higher->name === $position->post_name) {
            throw new WorkflowException(__('vacancymaster.errors.higher_post_invalid'), 'higher_post_id');
        }

        $reference = trim((string) ($data['higher_dpc_reference'] ?? ''));

        if ($reference === '') {
            throw new WorkflowException(__('vacancymaster.errors.higher_dpc_reference_required'), 'higher_dpc_reference');
        }

        // Rule 1.2: counted only with the department's undertaking.
        if (! filter_var($data['higher_post_undertaking'] ?? false, FILTER_VALIDATE_BOOL)) {
            throw new WorkflowException(__('vacancymaster.errors.higher_undertaking_required'), 'higher_post_undertaking');
        }

        // Linked when the reference is a DPC this portal holds, so its status can be read.
        $proposal = DpcProposal::query()->where('dpc_code', $reference)->first();

        return [
            'higher_post_id' => $higher->id,
            'higher_dpc_reference' => mb_substr($reference, 0, 60),
            'higher_dpc_proposal_id' => $proposal?->id,
            'promotion_order_number' => $this->clean($data['promotion_order_number'] ?? null),
            'promotion_order_date' => empty($data['promotion_order_date']) ? null : Carbon::parse((string) $data['promotion_order_date'])->toDateString(),
            'higher_post_undertaking_at' => now(),
        ];
    }

    /**
     * The same order cannot be entered twice for the same vacancy, and one higher-
     * post DPC can be cited by one entry only.
     */
    private function assertNotDuplicate(
        VacancyPosition $position,
        VacancyEntryKind $kind,
        VacancyReason $reason,
        Carbon $effective,
        string $orderNumber,
        ?string $quota,
        ?string $higherReference,
    ): void {
        $active = VacancyPositionEntry::query()
            ->where('vacancy_position_id', $position->id)
            ->where('status', VacancyPositionEntry::STATUS_ACTIVE);

        $duplicate = (clone $active)
            ->where('kind', $kind->value)
            ->where('reason', $reason->value)
            ->whereDate('effective_date', $effective->toDateString())
            ->where('order_number', trim($orderNumber))
            ->where(fn ($query) => $quota === null ? $query->whereNull('occupant_quota') : $query->where('occupant_quota', $quota))
            ->exists();

        if ($duplicate) {
            throw new WorkflowException(__('vacancymaster.errors.duplicate_entry'));
        }

        if ($higherReference !== null && (clone $active)->where('higher_dpc_reference', $higherReference)->exists()) {
            throw new WorkflowException(__('vacancymaster.errors.duplicate_higher_dpc', ['reference' => $higherReference]), 'higher_dpc_reference');
        }
    }

    /**
     * @return array{disk: string, path: string, original_name: string, size_bytes: int, sha256: string}
     */
    private function store(UploadedFile $file, VacancyPosition $position, string $folder): array
    {
        $disk = (string) config('dpc.documents.disk', 'local');
        $contents = (string) file_get_contents($file->getRealPath());

        $path = Storage::disk($disk)->putFile(sprintf('private/vacancy-master/%d/%s', $position->id, $folder), $file);

        if ($path === false) {
            throw new WorkflowException(__('vacancymaster.errors.store_failed'));
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'size_bytes' => $file->getSize() ?: 0,
            'sha256' => hash('sha256', $contents),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(string $event, VacancyPosition $position, string $description, array $properties, User $actor): void
    {
        $this->timeline->record($event, $position, $description, [
            'department_id' => $position->department_id,
            'post_name' => $position->post_name,
            'vacancy_year' => $position->vacancy_year,
            'revision' => $position->revision,
            ...$properties,
        ], $actor);
    }

    private function assertDraft(VacancyPosition $position): void
    {
        if (! $position->isDraft()) {
            throw new WorkflowException(__('vacancymaster.errors.not_draft'));
        }
    }

    private function assertYear(int $year): void
    {
        $earliest = (int) config('dpc.vacancy_master.earliest_year', 2019);

        if ($year < $earliest || $year > (int) now()->year + 1) {
            throw new WorkflowException(__('vacancymaster.errors.year_out_of_range', [
                'from' => $earliest,
                'to' => (int) now()->year + 1,
            ]));
        }
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
