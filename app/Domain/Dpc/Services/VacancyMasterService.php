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
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\Models\VacancyPositionEntry;
use App\Domain\Dpc\Models\VacancyPositionSanctionedStrength;
use App\Domain\Dpc\Models\VacancyPositionWorkingStrength;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\PreDpc\Models\PreDpcProposal;
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
 * ### Since 2026-10-30
 *
 * One record per approved Pre-DPC (its post, year and promotion share), counted as
 * A clear + B deputation + C anticipated — see `breakdown()`. Whether a row counts
 * is decided from its own dates when it is saved; no row waits for verification.
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
     * Every figure of the record, how each was reached, and what still stops it from
     * being finalized (decisions of 2026-10-30):
     *
     *     working = our officers (those on deputation elsewhere included)
     *             + officers of other departments on deputation here     (B1 rows)
     *     A  clear              = sanctioned − working
     *     B1 deputation ending  = B1 rows whose deputation ends in the Vacancy Year
     *     B2 deputation out     = our officers away more than one year by 31 December
     *                             with no promotion made against their post
     *     C  anticipated        = retirements and promotions to a higher post in the year
     *     total                 = A + B1 + B2 + C, never above the sanctioned strength
     *     promotion vacancy     = total × the Pre-DPC's promotion share (rule 1.3 rounding)
     *     direct vacancy        = total − promotion vacancy
     *
     * A finalized or superseded record answers from its frozen snapshot, so a later
     * change to a mapping, a rule or the Pre-DPC never alters it.
     *
     * @return array<string, mixed>
     */
    public function breakdown(VacancyPosition $position): array
    {
        if (! $position->isDraft() && is_array($position->breakdown)) {
            return $position->breakdown;
        }

        $position->loadMissing('sanctionedStrength', 'workingStrength', 'entries', 'preDpcProposal');

        [$from, $to] = $this->engine->periodForYear($position->vacancy_year);
        $mapping = $this->mappingFor($position);
        $rule = $this->ruleFor($position, $from);
        $preDpc = $position->preDpcProposal;
        // Entered for this Vacancy Year — never the Post Mapping's figure.
        $sanctionedRecord = $position->sanctionedStrength;
        $sanctioned = $sanctionedRecord?->sanctioned_total;
        // The Pre-DPC's share; the Recruitment Rule's only for a record without one.
        $percentage = $preDpc !== null ? $preDpc->promotion_recruitment_pct : $rule?->promotion_recruitment_pct;
        $working = $position->workingStrength;

        $active = $position->entries->filter(fn (VacancyPositionEntry $entry): bool => $entry->isActive());
        $ofKind = fn (VacancyEntryKind $kind): Collection => $active->filter(fn (VacancyPositionEntry $entry): bool => $entry->kind === $kind);
        $counted = fn (Collection $entries): int => (int) $entries->filter(fn (VacancyPositionEntry $entry): bool => $entry->isCounted())->sum('vacancy_count');

        $incoming = $ofKind(VacancyEntryKind::DeputationIn);
        $outgoing = $ofKind(VacancyEntryKind::Deputation);
        $anticipated = $ofKind(VacancyEntryKind::Anticipated);
        $pending = $active->filter(fn (VacancyPositionEntry $entry): bool => $entry->eligibility === VacancyEntryEligibility::PendingVerification);

        $own = $working?->working_total;
        $workingTotal = $own === null ? null : $own + (int) $incoming->sum('vacancy_count');
        $clear = $sanctioned === null || $workingTotal === null ? null : max(0, $sanctioned - $workingTotal);
        $deputationIn = $counted($incoming);
        $deputationOut = $counted($outgoing);
        $arising = $counted($anticipated);
        $total = $clear === null ? null : $clear + $deputationIn + $deputationOut + $arising;

        $quota = $total === null
            ? null
            : $this->engine->quotaPosition($total, $percentage === null ? null : (string) $percentage, 0, 0);

        $issues = [];

        if ($preDpc === null) {
            $issues[] = ['key' => 'no_pre_dpc', 'params' => ['year' => $position->vacancy_year]];
        }

        if ($mapping === null) {
            $issues[] = ['key' => 'no_mapping', 'params' => ['date' => $from->format('d-m-Y')]];
        }

        if ($sanctionedRecord === null) {
            $issues[] = ['key' => 'sanctioned_not_entered', 'params' => ['year' => $position->vacancy_year]];
        }

        if ($working === null) {
            $issues[] = ['key' => 'working_not_entered', 'params' => []];
        }

        if ($workingTotal !== null && $sanctioned !== null && $workingTotal > $sanctioned) {
            $issues[] = ['key' => 'working_exceeds_sanctioned', 'params' => [
                'working' => $workingTotal,
                'sanctioned' => $sanctioned,
            ]];
        }

        if ($percentage === null) {
            $issues[] = ['key' => 'no_percentage', 'params' => []];
        }

        if ($pending->isNotEmpty()) {
            $issues[] = ['key' => 'pending_entries', 'params' => ['count' => $pending->count()]];
        }

        if ($total !== null && $total > $sanctioned) {
            $issues[] = ['key' => 'total_exceeds_sanctioned', 'params' => ['total' => $total, 'sanctioned' => $sanctioned]];
        }

        return [
            'scheme' => 2,
            'vacancy_year' => $position->vacancy_year,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'department_post_id' => $mapping?->id,
            'pre_dpc_proposal_id' => $preDpc?->id,
            'pre_dpc_number' => $preDpc?->pre_dpc_number,
            'sanctioned' => $sanctioned,
            'sanctioned_order' => $sanctionedRecord === null ? null : [
                'order_number' => $sanctionedRecord->order_number,
                'order_date' => $sanctionedRecord->order_date->toDateString(),
                'document_sha256' => $sanctionedRecord->sha256,
            ],
            'recruitment_rule_id' => $rule?->id,
            'recruitment_rule_version' => $rule?->version,
            'promotion_percentage' => $percentage === null ? null : number_format((float) $percentage, 2, '.', ''),
            'rounding' => $this->engine->rounding(),
            'working' => $working === null ? null : [
                'total' => $workingTotal,
                'own' => $own,
                'deputation_in' => (int) $incoming->sum('vacancy_count'),
                // No quota split since 2026-10-30; kept so a reader of an older record
                // finds the same keys.
                'promotion' => null,
                'direct' => null,
                'deputation' => (int) $incoming->sum('vacancy_count'),
                'order_number' => $working->order_number,
                'order_date' => $working->order_date?->toDateString(),
                'document_sha256' => $working->sha256,
            ],
            'opening_vacancy' => $clear,
            'clear_vacancy' => $clear,
            'deputation_in_vacancy' => $deputationIn,
            'deputation_out_vacancy' => $deputationOut,
            'anticipated_vacancy' => $arising,
            'retirement_vacancy' => $counted($anticipated->filter(fn (VacancyPositionEntry $entry): bool => $entry->reason === VacancyReason::Retirement)),
            'total_vacancy' => $total,
            'final_quota' => $quota,
            'final_total' => $total,
            'entries' => $active->map(fn (VacancyPositionEntry $entry): array => [
                'uuid' => $entry->uuid,
                'kind' => $entry->kind->value,
                'reason' => $entry->reason->value,
                'officer_name' => $entry->officer_name,
                'count' => $entry->vacancy_count,
                'effective_date' => $entry->effective_date->toDateString(),
                'deputation_from' => $entry->deputation_from?->toDateString(),
                'deputation_to' => $entry->deputation_to?->toDateString(),
                'promotion_made' => $entry->promotion_made,
                'eligibility' => $entry->eligibility->value,
                'counted' => $entry->isCounted(),
                'order_number' => $entry->order_number,
                'document_sha256' => $entry->sha256,
            ])->values()->all(),
            'issues' => $issues,
            'complete' => $issues === [],
        ];
    }

    /**
     * Whether a breakdown is of a record kept after 2026-10-30 (A + B + C). Older
     * finalized records keep their own figures and are read the old way.
     *
     * @param  array<string, mixed>|null  $breakdown
     */
    public static function isPerPreDpc(?array $breakdown): bool
    {
        return (int) ($breakdown['scheme'] ?? 1) === 2;
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
     * A record belongs to the approved Pre-DPC of the department, post and year
     * (2026-10-30): its year and post come from it, and so does the promotion share.
     *
     * @throws WorkflowException when no approved Pre-DPC exists, or a finalized record does (correct it by revision)
     */
    public function openDraft(Department $department, DepartmentPost $post, int $year, User $actor): VacancyPosition
    {
        $this->assertYear($year);

        if ($post->department_id !== $department->id) {
            throw new WorkflowException(__('vacancymaster.errors.post_not_in_department'));
        }

        $preDpc = $this->approvedPreDpc($department->id, $post->name, $year);

        if ($preDpc === null) {
            throw new WorkflowException(__('vacancymaster.errors.no_approved_pre_dpc', ['post' => $post->name, 'year' => $year]));
        }

        return DB::transaction(function () use ($department, $post, $year, $actor, $preDpc): VacancyPosition {
            $current = $this->current($department->id, $post->name, $year);

            if ($current !== null && $current->isDraft()) {
                if ($current->pre_dpc_proposal_id !== $preDpc->id) {
                    $current->forceFill(['pre_dpc_proposal_id' => $preDpc->id])->save();
                }

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
                'pre_dpc_proposal_id' => $preDpc->id,
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
            ]), ['pre_dpc' => $preDpc->pre_dpc_number], $actor);

            return $position;
        });
    }

    /**
     * The approved, active Pre-DPC of a department's post for a DPC year — the one a
     * Vacancy Management record is kept for.
     */
    public function approvedPreDpc(int $departmentId, string $postName, int $year): ?PreDpcProposal
    {
        return PreDpcProposal::query()
            ->activeApproved()
            ->where('department_id', $departmentId)
            ->where('dpc_year', $year)
            ->whereHas('promotionToPost', fn ($query) => $query->where('name', $postName))
            ->latest('approved_at')
            ->first();
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

        return $this->withStored($document, $position, 'sanctioned-strength', function (array $stored) use ($position, $data, $actor, $total, $previous, $reason): VacancyPositionSanctionedStrength {

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
            $this->assertWithinSanctioned($position, 'sanctioned_total');

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
     * Record (or correct) the working strength as on 1 January: one figure, our own
     * officers on the post, those away on deputation included. Officers of other
     * departments working here on deputation are named row by row (B1) and added to
     * it. The order and its PDF are optional.
     *
     * @param  array{working_total: int, order_number?: string|null, order_date?: string|null, remarks?: string|null, correction_reason?: string|null}  $data
     */
    public function saveWorkingStrength(VacancyPosition $position, array $data, ?UploadedFile $document, User $actor): VacancyPositionWorkingStrength
    {
        $this->assertDraft($position);

        $total = (int) $data['working_total'];
        $previous = $position->workingStrength;
        $reason = trim((string) ($data['correction_reason'] ?? ''));

        if ($previous !== null && $reason === '') {
            throw new WorkflowException(__('vacancymaster.errors.correction_reason_required'), 'correction_reason');
        }

        return $this->withStored($document, $position, 'working-strength', function (array $stored) use ($position, $data, $actor, $total, $previous, $reason): VacancyPositionWorkingStrength {
            $row = VacancyPositionWorkingStrength::query()->create([
                'vacancy_position_id' => $position->id,
                'working_total' => $total,
                'exceeds_sanctioned_acknowledged' => false,
                'order_number' => $this->clean($data['order_number'] ?? null),
                'order_date' => empty($data['order_date']) ? null : Carbon::parse((string) $data['order_date'])->toDateString(),
                ...$stored,
                'remarks' => $this->clean($data['remarks'] ?? null),
                'correction_reason' => $previous === null ? null : $reason,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);

            $position->forceFill(['working_strength_id' => $row->id])->save();
            $this->assertWithinSanctioned($position, 'working_total');

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
                    'previous' => $previous?->only(['working_total', 'order_number']),
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
     * Add one row of the record: an officer here on deputation (B1), an officer of
     * ours on deputation elsewhere (B2), or a retirement or promotion to a higher
     * post during the year (C). One row is one officer and one post.
     *
     * Whether the row counts is decided here, from its dates and the Vacancy Year,
     * and stated on it. Nothing waits for anyone's verification.
     *
     * @param  array<string, mixed>  $data
     */
    public function addEntry(VacancyPosition $position, array $data, ?UploadedFile $document, User $actor): VacancyPositionEntry
    {
        $this->assertDraft($position);

        if ($position->sanctionedStrength === null || $position->workingStrength === null) {
            throw new WorkflowException(__('vacancymaster.errors.figures_first'));
        }

        $kind = VacancyEntryKind::tryFrom((string) ($data['kind'] ?? ''));

        if ($kind === null || $kind === VacancyEntryKind::Existing) {
            throw new WorkflowException(__('vacancymaster.errors.kind_invalid'), 'kind');
        }

        $name = trim((string) ($data['officer_name'] ?? ''));

        if ($name === '') {
            throw new WorkflowException(__('vacancymaster.errors.officer_name_required'), 'officer_name');
        }

        $this->assertNotDuplicate($position, $kind, $name);

        [$from, $to] = $this->engine->periodForYear($position->vacancy_year);
        $attributes = match ($kind) {
            VacancyEntryKind::DeputationIn => $this->deputationIn($data, $from, $to),
            VacancyEntryKind::Deputation => $this->deputationOut($data, $to),
            default => $this->anticipated($position, $data, $document, $from, $to),
        };

        return $this->withStored($document, $position, 'entries', function (array $stored) use ($position, $data, $actor, $kind, $name, $attributes): VacancyPositionEntry {
            $entry = VacancyPositionEntry::query()->create([
                'vacancy_position_id' => $position->id,
                'kind' => $kind->value,
                'officer_name' => mb_substr($name, 0, 255),
                'vacancy_count' => 1,
                'order_number' => $this->clean($data['order_number'] ?? null),
                'order_date' => empty($data['order_date']) ? null : Carbon::parse((string) $data['order_date'])->toDateString(),
                ...$stored,
                'remarks' => $this->clean($data['remarks'] ?? null),
                ...$attributes,
                'status' => VacancyPositionEntry::STATUS_ACTIVE,
                'recorded_by' => $actor->id,
            ]);

            $position->unsetRelation('entries');
            $this->assertWithinSanctioned($position, 'kind');

            $this->record('vacancy_position.entry_added', $position, __('vacancymaster.timeline.entry_added_named', [
                'kind' => $kind->label(),
                'name' => $entry->officer_name,
                'reason' => $entry->reason->label(),
                'eligibility' => $entry->eligibility->label(),
            ]), [
                'entry' => $entry->uuid,
                'kind' => $kind->value,
                'reason' => $entry->reason->value,
                'counted' => $entry->isCounted(),
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
                'pre_dpc_proposal_id' => $position->pre_dpc_proposal_id,
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
     * B1: an officer of another department working here on deputation. In the
     * working strength; the post falls vacant when the deputation ends inside the
     * Vacancy Year. A deputation already over before 1 January is refused — that
     * officer is not working here, and the post is in the clear vacancy.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function deputationIn(array $data, Carbon $from, Carbon $to): array
    {
        if (empty($data['deputation_from'])) {
            throw new WorkflowException(__('vacancymaster.errors.deputation_came_required'), 'deputation_from');
        }

        if (empty($data['deputation_to'])) {
            throw new WorkflowException(__('vacancymaster.errors.deputation_ends_required'), 'deputation_to');
        }

        $came = Carbon::parse((string) $data['deputation_from'])->startOfDay();
        $ends = Carbon::parse((string) $data['deputation_to'])->startOfDay();

        if ($ends->lt($came)) {
            throw new WorkflowException(__('vacancymaster.errors.deputation_ends_before_came'), 'deputation_to');
        }

        if ($ends->lt($from)) {
            throw new WorkflowException(__('vacancymaster.errors.deputation_already_ended', ['date' => $from->format('d-m-Y')]), 'deputation_to');
        }

        $counted = $ends->lte($to);

        return [
            'reason' => VacancyReason::Deputation->value,
            'effective_date' => $ends->toDateString(),
            'deputation_from' => $came->toDateString(),
            'deputation_to' => $ends->toDateString(),
            'eligibility' => ($counted ? VacancyEntryEligibility::Eligible : VacancyEntryEligibility::NotEligible)->value,
            'eligibility_note' => __($counted ? 'vacancymaster.notes.deputation_in_ends' : 'vacancymaster.notes.deputation_in_continues', [
                'date' => $ends->format('d-m-Y'),
                'year' => $from->year,
            ]),
        ];
    }

    /**
     * B2 (rule 2.3): an officer of ours on deputation at another department. In the
     * working strength; the post is a vacancy when the deputation is more than one
     * year by 31 December and the department declares no promotion has been made
     * against it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function deputationOut(array $data, Carbon $to): array
    {
        if (empty($data['deputation_from'])) {
            throw new WorkflowException(__('vacancymaster.errors.deputation_went_required'), 'deputation_from');
        }

        if (! isset($data['promotion_made']) || ! in_array((string) $data['promotion_made'], ['0', '1'], true)) {
            throw new WorkflowException(__('vacancymaster.errors.promotion_made_required'), 'promotion_made');
        }

        $went = Carbon::parse((string) $data['deputation_from'])->startOfDay();
        $yearEnd = $to->copy()->startOfDay();

        if ($went->gt($yearEnd)) {
            throw new WorkflowException(__('vacancymaster.errors.deputation_went_after_year', ['date' => $yearEnd->format('d-m-Y')]), 'deputation_from');
        }

        $promotionMade = (string) $data['promotion_made'] === '1';
        $overAYear = $went->copy()->addYear()->lt($yearEnd);
        $counted = $overAYear && ! $promotionMade;

        return [
            'reason' => VacancyReason::Deputation->value,
            'effective_date' => $went->toDateString(),
            'deputation_from' => $went->toDateString(),
            'promotion_made' => $promotionMade,
            'eligibility' => ($counted ? VacancyEntryEligibility::Eligible : VacancyEntryEligibility::NotEligible)->value,
            'eligibility_note' => __(match (true) {
                $promotionMade => 'vacancymaster.notes.deputation_out_promotion_made',
                ! $overAYear => 'vacancymaster.notes.deputation_out_within_a_year',
                default => 'vacancymaster.notes.deputation_out_over_a_year',
            }, ['date' => $yearEnd->format('d-m-Y')]),
        ];
    }

    /**
     * C: a retirement, or a promotion to a higher post, inside the Vacancy Year. A
     * promotion carries the higher post, its DPC, the undertaking of rule 1.2 and the
     * order with its PDF.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function anticipated(VacancyPosition $position, array $data, ?UploadedFile $document, Carbon $from, Carbon $to): array
    {
        $reason = VacancyReason::tryFrom((string) ($data['reason'] ?? ''));

        if (! in_array($reason, [VacancyReason::Retirement, VacancyReason::HigherPostPromotion], true)) {
            throw new WorkflowException(__('vacancymaster.errors.reason_invalid'), 'reason');
        }

        if (empty($data['effective_date'])) {
            throw new WorkflowException(__('vacancymaster.errors.date_required'), 'effective_date');
        }

        $effective = Carbon::parse((string) $data['effective_date'])->startOfDay();

        // The Vacancy Year boundaries, both inclusive.
        if (! $effective->betweenIncluded($from->copy()->startOfDay(), $to)) {
            throw new WorkflowException(__('vacancymaster.errors.anticipated_outside_year', [
                'from' => $from->format('d-m-Y'),
                'to' => $to->format('d-m-Y'),
            ]), 'effective_date');
        }

        $higher = $this->higherPostReference($position, $reason, $data);

        if ($reason === VacancyReason::HigherPostPromotion) {
            if (trim((string) ($data['order_number'] ?? '')) === '') {
                throw new WorkflowException(__('vacancymaster.errors.order_number_required'), 'order_number');
            }

            if (empty($data['order_date'])) {
                throw new WorkflowException(__('vacancymaster.errors.order_date_required'), 'order_date');
            }

            if ($document === null) {
                throw new WorkflowException(__('vacancymaster.errors.document_required'), 'document');
            }
        }

        return [
            'reason' => $reason->value,
            'effective_date' => $effective->toDateString(),
            ...$higher,
            'eligibility' => VacancyEntryEligibility::Eligible->value,
            'eligibility_note' => __($reason === VacancyReason::Retirement ? 'vacancymaster.notes.retirement' : 'vacancymaster.notes.higher_post_counted'),
        ];
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
            'promotion_order_number' => $this->clean($data['order_number'] ?? null),
            'promotion_order_date' => empty($data['order_date']) ? null : Carbon::parse((string) $data['order_date'])->toDateString(),
            'higher_post_undertaking_at' => now(),
        ];
    }

    /**
     * One officer is one row of a kind: the same name cannot be entered twice.
     */
    private function assertNotDuplicate(VacancyPosition $position, VacancyEntryKind $kind, string $name): void
    {
        $exists = VacancyPositionEntry::query()
            ->where('vacancy_position_id', $position->id)
            ->where('status', VacancyPositionEntry::STATUS_ACTIVE)
            ->where('kind', $kind->value)
            ->whereRaw('LOWER(TRIM(officer_name)) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            throw new WorkflowException(__('vacancymaster.errors.duplicate_officer', ['name' => $name]), 'officer_name');
        }
    }

    /**
     * Neither the working strength nor the total vacancy may exceed the sanctioned
     * strength. Called inside the write's transaction, after the write, so the
     * figures are the ones that would be kept; throwing rolls the write back.
     */
    private function assertWithinSanctioned(VacancyPosition $position, string $field): void
    {
        $fresh = VacancyPosition::query()->findOrFail($position->id);
        $breakdown = $this->breakdown($fresh);
        $sanctioned = $breakdown['sanctioned'];

        if ($sanctioned === null) {
            return;
        }

        $working = $breakdown['working']['total'] ?? null;

        if ($working !== null && $working > $sanctioned) {
            throw new WorkflowException(__('vacancymaster.errors.working_over_sanctioned', ['working' => $working, 'sanctioned' => $sanctioned]), $field);
        }

        if ($breakdown['total_vacancy'] !== null && $breakdown['total_vacancy'] > $sanctioned) {
            throw new WorkflowException(__('vacancymaster.errors.total_over_sanctioned', ['total' => $breakdown['total_vacancy'], 'sanctioned' => $sanctioned]), $field);
        }
    }

    /**
     * Store an optional PDF, then run the write in a transaction with it; a refused
     * write removes the file it stored.
     *
     * @template T
     *
     * @param  callable(array<string, mixed>): T  $write
     * @return T
     */
    private function withStored(?UploadedFile $document, VacancyPosition $position, string $folder, callable $write): mixed
    {
        $stored = $document === null ? [] : $this->store($document, $position, $folder);

        try {
            return DB::transaction(fn () => $write($stored));
        } catch (\Throwable $e) {
            if ($stored !== []) {
                Storage::disk($stored['disk'])->delete($stored['path']);
            }

            throw $e;
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
