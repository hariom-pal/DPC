<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Services;

use App\Domain\Dpc\Enums\VacancyEntryKind;
use App\Domain\Dpc\Enums\VacancyPositionStatus;
use App\Domain\Dpc\Enums\VacancyReason;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcWorkingOfficer;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\ValueObjects\VacancyBreakdown;
use Illuminate\Support\Carbon;

/**
 * The Administrator's finalized Vacancy Management record behind a proposal whose
 * vacancy source is `vacancy_master`, and the engine's breakdown read from it.
 *
 * ### Which record
 *
 * Same department, the proposal's Promotion To Post and its DPC year — never another
 * year. While the proposal is the AO's to edit, the latest finalized revision; once
 * submitted, the revision pinned at submission (`vacancy_position_id`), so a later
 * revision by the Administrator never changes a proposal already sent on.
 *
 * ### The figures
 *
 * Read from the record's frozen breakdown, which `VacancyMasterService` computed with
 * the engine's own `quotaPosition()` — the same rounding and the same chair
 * arithmetic the Working Officers chart goes through:
 *
 *     chairs held (promotion) = working (promotion) − anticipated vacancies counted against promotion
 *     chairs held (direct)    = working (direct)    − anticipated vacancies counted against direct
 *     promotion vacancy       = promotion chairs − chairs held (promotion)
 *
 * A record kept per Pre-DPC since 2026-10-30 (`scheme` 2) is read as its own
 * A + B + C total and the Pre-DPC's share of it — see `VacancyMasterService::breakdown()`.
 *
 * Nothing is recalculated here and nothing is guessed: no record, no figures.
 */
final class ProposalVacancyPosition
{
    public function for(DpcProposal $proposal): ?VacancyPosition
    {
        $post = $proposal->promotionToPost;

        if ($post === null) {
            return null;
        }

        $year = (int) ($proposal->dpc_year ?: $proposal->proposal_date->year);

        // Submitted proposals, and Review DPCs (which inherit the original's), keep
        // the revision pinned to them.
        if ($proposal->vacancy_position_id !== null
            && (! $proposal->status->isEditable() || $proposal->original_dpc_proposal_id !== null)) {
            $pinned = VacancyPosition::query()->find($proposal->vacancy_position_id);

            if ($pinned !== null && $this->matches($pinned, $proposal, $post->name, $year)) {
                return $pinned;
            }
        }

        return VacancyPosition::query()
            ->where('department_id', $proposal->department_id)
            ->where('post_name', $post->name)
            ->where('vacancy_year', $year)
            ->where('status', VacancyPositionStatus::Finalized->value)
            ->orderByDesc('revision')
            ->first();
    }

    /**
     * The engine's breakdown for a `vacancy_master` proposal. Incomplete (null
     * figures) when no finalized record exists, exactly as an incomplete chart is.
     */
    public function breakdown(DpcProposal $proposal, Carbon $periodFrom, Carbon $periodTo, string $rounding): VacancyBreakdown
    {
        $position = $this->for($proposal);
        $frozen = $position?->breakdown;

        if (! is_array($frozen) || ! is_array($frozen['final_quota'] ?? null) || ! is_array($frozen['working'] ?? null)) {
            return new VacancyBreakdown(
                sanctionedStrength: null,
                workingStrength: 0,
                chartRows: 0,
                baseVacancy: null,
                retirementVacancy: 0,
                deputationVacancy: 0,
                totalVacancy: null,
                promotionPercentage: null,
                promotionChairs: null,
                directChairs: null,
                promotionChairsHeld: 0,
                directChairsHeld: 0,
                deputationChairsHeld: 0,
                promotionVacancy: null,
                directRecruitmentVacancy: null,
                periodFrom: $periodFrom,
                periodTo: $periodTo,
                roundingRule: $rounding,
            );
        }

        $working = $frozen['working'];
        $quota = $frozen['final_quota'];
        $sanctioned = $frozen['sanctioned'] === null ? null : (int) $frozen['sanctioned'];

        if (VacancyMasterService::isPerPreDpc($frozen)) {
            return $this->perPreDpcBreakdown($frozen, $sanctioned, $periodFrom, $periodTo, $rounding);
        }

        $heldPromotion = (int) $working['promotion'] - (int) $frozen['anticipated_counted_promotion'];
        $heldDirect = (int) $working['direct'] - (int) $frozen['anticipated_counted_direct'];

        $counted = array_values(array_filter((array) ($frozen['entries'] ?? []), static fn (array $entry): bool => ($entry['counted'] ?? false) === true));
        $countedOn = static fn (string $reason): int => (int) array_sum(array_map(
            static fn (array $entry): int => $entry['reason'] === $reason ? (int) $entry['count'] : 0,
            $counted,
        ));

        return new VacancyBreakdown(
            sanctionedStrength: $sanctioned,
            workingStrength: (int) $working['total'],
            // No chart: the Administrator's working strength stands for it, and was
            // reconciled with the sanctioned strength before finalization.
            chartRows: max((int) $working['total'], (int) $sanctioned),
            baseVacancy: $frozen['opening_vacancy'] === null ? null : (int) $frozen['opening_vacancy'],
            retirementVacancy: $countedOn(VacancyReason::Retirement->value),
            deputationVacancy: $countedOn(VacancyReason::Deputation->value),
            totalVacancy: $sanctioned === null ? null : $sanctioned - $heldPromotion - $heldDirect,
            promotionPercentage: $frozen['promotion_percentage'] === null ? null : (string) $frozen['promotion_percentage'],
            promotionChairs: $quota['promotion_chairs'] === null ? null : (int) $quota['promotion_chairs'],
            directChairs: $quota['direct_chairs'] === null ? null : (int) $quota['direct_chairs'],
            promotionChairsHeld: $heldPromotion,
            directChairsHeld: $heldDirect,
            deputationChairsHeld: (int) ($working['deputation'] ?? 0),
            promotionVacancy: $quota['promotion_vacancy'] === null ? null : (int) $quota['promotion_vacancy'],
            directRecruitmentVacancy: $quota['direct_vacancy'] === null ? null : (int) $quota['direct_vacancy'],
            periodFrom: Carbon::parse((string) $frozen['period_from'])->startOfDay(),
            periodTo: Carbon::parse((string) $frozen['period_to'])->startOfDay(),
            roundingRule: (string) ($frozen['rounding'] ?? $rounding),
            // The record counts vacancies, not named officers; its entries are shown
            // from the record itself.
            contributors: [],
            sanctionedFromPostId: $frozen['department_post_id'] === null ? null : (int) $frozen['department_post_id'],
            recruitmentRuleId: $frozen['recruitment_rule_id'] === null ? null : (int) $frozen['recruitment_rule_id'],
        );
    }

    /**
     * The rows a `vacancy_master` proposal stores in `dpc_vacancies` for the scoring
     * engine: its promotion vacancy, in the legacy vacancy types the table requires.
     *
     * The opening (1 January) part is `clear`; vacancies arising in the year whose
     * officer held a promotion chair are `from_promotion` — the same meaning those
     * codes carry on the chart. Only the single pool is supported: the record holds
     * no reservation category per vacancy.
     *
     * @param  array<string, int>  $typeIds  vacancy type code => id
     * @return list<array{vacancy_type_id: int, reservation_category_id: int, count: int}>
     */
    public function rows(DpcProposal $proposal, array $typeIds, int $poolCategoryId): array
    {
        $frozen = $this->for($proposal)?->breakdown;
        $total = (int) (is_array($frozen) ? ($frozen['final_quota']['promotion_vacancy'] ?? 0) : 0);

        if ($total < 1 || ! isset($typeIds['clear'], $typeIds['from_promotion'])) {
            return [];
        }

        // Arising in the year: on a record kept per Pre-DPC, the deputations ending
        // here (B1) and the retirements and promotions (C); A and B2 are open on
        // 1 January.
        $arising = min($total, max(0, VacancyMasterService::isPerPreDpc($frozen)
            ? (int) ($frozen['deputation_in_vacancy'] ?? 0) + (int) ($frozen['anticipated_vacancy'] ?? 0)
            : (int) ($frozen['anticipated_counted_promotion'] ?? 0)));
        $opening = $total - $arising;

        $rows = [];

        if ($opening > 0) {
            $rows[] = ['vacancy_type_id' => $typeIds['clear'], 'reservation_category_id' => $poolCategoryId, 'count' => $opening];
        }

        if ($arising > 0) {
            $rows[] = ['vacancy_type_id' => $typeIds['from_promotion'], 'reservation_category_id' => $poolCategoryId, 'count' => $arising];
        }

        return $rows;
    }

    /**
     * Existing and anticipated vacancies of the record, for the AO's read-only view.
     *
     * @return array{existing: int, anticipated: int}
     */
    public function counts(VacancyPosition $position): array
    {
        $entries = (array) (($position->breakdown ?? [])['entries'] ?? []);

        $sum = static fn (string $kind): int => (int) array_sum(array_map(
            static fn (array $entry): int => $entry['kind'] === $kind ? (int) $entry['count'] : 0,
            $entries,
        ));

        if (VacancyMasterService::isPerPreDpc($position->breakdown)) {
            return [
                'existing' => (int) ($position->breakdown['clear_vacancy'] ?? 0) + (int) ($position->breakdown['deputation_out_vacancy'] ?? 0),
                'anticipated' => (int) ($position->breakdown['deputation_in_vacancy'] ?? 0) + (int) ($position->breakdown['anticipated_vacancy'] ?? 0),
            ];
        }

        return ['existing' => $sum(VacancyEntryKind::Existing->value), 'anticipated' => $sum(VacancyEntryKind::Anticipated->value)];
    }

    /**
     * The AO's Working Officers List against the finalized record (step 1 of a
     * Vacancy Management proposal): officers working on the post, by the quota
     * their chair is held against, beside the record's working strength.
     *
     * The list is evidence that the record's working strength names real officers;
     * the vacancy is still calculated from the record alone. `differences` holds
     * each figure that does not agree, and `matches` is true only when a list is
     * uploaded, a record is finalized and all four figures agree.
     *
     * @return array{
     *     uploaded: array{total: int, promotion: int, direct: int, deputation: int}|null,
     *     record: array{total: int, promotion: int, direct: int, deputation: int}|null,
     *     differences: array<string, array{uploaded: int, record: int}>,
     *     matches: bool,
     *     figures: list<string>
     * }
     */
    public function reconcile(DpcProposal $proposal): array
    {
        $frozen = $this->for($proposal)?->breakdown;
        $working = $frozen['working'] ?? null;
        // A record kept per Pre-DPC has no quota split: only the total and the
        // officers here on deputation are compared.
        $figures = VacancyMasterService::isPerPreDpc($frozen) ? ['deputation', 'total'] : ['promotion', 'direct', 'deputation', 'total'];
        $record = is_array($working) ? [
            'total' => (int) ($working['total'] ?? 0),
            'promotion' => (int) ($working['promotion'] ?? 0),
            'direct' => (int) ($working['direct'] ?? 0),
            'deputation' => (int) ($working['deputation'] ?? 0),
        ] : null;

        $officers = $proposal->workingOfficers()->get();
        $uploaded = null;

        if ($officers->isNotEmpty()) {
            $serving = $officers->filter(fn (DpcWorkingOfficer $officer): bool => $officer->isWorking());
            $uploaded = [
                'total' => $serving->count(),
                'promotion' => $serving->where('source', DpcWorkingOfficer::SOURCE_PROMOTION)->count(),
                'direct' => $serving->where('source', DpcWorkingOfficer::SOURCE_DIRECT)->count(),
                'deputation' => $serving->where('source', DpcWorkingOfficer::SOURCE_DEPUTATION)->count(),
            ];
        }

        $differences = [];

        if ($uploaded !== null && $record !== null) {
            foreach ($figures as $figure) {
                if ($uploaded[$figure] !== $record[$figure]) {
                    $differences[$figure] = ['uploaded' => $uploaded[$figure], 'record' => $record[$figure]];
                }
            }
        }

        return [
            'uploaded' => $uploaded,
            'record' => $record,
            'differences' => $differences,
            'matches' => $uploaded !== null && $record !== null && $differences === [],
            'figures' => $figures,
        ];
    }

    /**
     * The breakdown of a record kept per Pre-DPC (2026-10-30): A + B1 + B2 + C, and
     * the Pre-DPC's share of that total.
     *
     * @param  array<string, mixed>  $frozen
     */
    private function perPreDpcBreakdown(array $frozen, ?int $sanctioned, Carbon $periodFrom, Carbon $periodTo, string $rounding): VacancyBreakdown
    {
        $quota = $frozen['final_quota'];
        $total = $frozen['total_vacancy'] === null ? null : (int) $frozen['total_vacancy'];

        return new VacancyBreakdown(
            sanctionedStrength: $sanctioned,
            workingStrength: (int) $frozen['working']['total'],
            // The record was kept within the sanctioned strength.
            chartRows: (int) $sanctioned,
            baseVacancy: $frozen['clear_vacancy'] === null ? null : (int) $frozen['clear_vacancy'],
            retirementVacancy: (int) ($frozen['retirement_vacancy'] ?? 0),
            deputationVacancy: (int) $frozen['deputation_in_vacancy'] + (int) $frozen['deputation_out_vacancy'],
            totalVacancy: $total,
            promotionPercentage: $frozen['promotion_percentage'] === null ? null : (string) $frozen['promotion_percentage'],
            promotionChairs: $quota['promotion_chairs'] === null ? null : (int) $quota['promotion_chairs'],
            directChairs: $quota['direct_chairs'] === null ? null : (int) $quota['direct_chairs'],
            promotionChairsHeld: 0,
            directChairsHeld: 0,
            deputationChairsHeld: (int) ($frozen['working']['deputation_in'] ?? 0),
            promotionVacancy: $quota['promotion_vacancy'] === null ? null : (int) $quota['promotion_vacancy'],
            directRecruitmentVacancy: $quota['direct_vacancy'] === null ? null : (int) $quota['direct_vacancy'],
            periodFrom: Carbon::parse((string) $frozen['period_from'])->startOfDay(),
            periodTo: Carbon::parse((string) $frozen['period_to'])->startOfDay(),
            roundingRule: (string) ($frozen['rounding'] ?? $rounding),
            contributors: [],
            sanctionedFromPostId: $frozen['department_post_id'] === null ? null : (int) $frozen['department_post_id'],
            recruitmentRuleId: $frozen['recruitment_rule_id'] === null ? null : (int) $frozen['recruitment_rule_id'],
        );
    }

    private function matches(VacancyPosition $position, DpcProposal $proposal, string $postName, int $year): bool
    {
        return $position->department_id === $proposal->department_id
            && $position->post_name === $postName
            && $position->vacancy_year === $year
            && $position->status !== VacancyPositionStatus::Draft;
    }
}
