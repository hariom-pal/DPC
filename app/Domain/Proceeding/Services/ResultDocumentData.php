<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Domain\Dpc\Enums\VacancyReason;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcProposalVerification;
use App\Domain\Dpc\Models\VacancyPositionEntry;
use App\Domain\Dpc\Services\VacancyEngine;
use App\Domain\Dpc\Services\VacancyMasterService;
use App\Domain\Eligibility\Enums\ListType;
use App\Domain\Eligibility\Enums\RemarkCode;
use App\Domain\Eligibility\Models\DpcCalculationResult;
use App\Domain\Eligibility\Models\DpcCalculationRun;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The data of a DPC's result documents (2026-11-25), gathered once when the result is
 * approved and kept with each document, so its signed copy says exactly what was
 * signed whatever changes in the database afterwards.
 *
 * Everything comes from the database. The legal sentences the Commission writes the
 * same in every DPC (the court note, the integrity and enquiry certificates, the 2003
 * Promotion Rules) are the template's, by instruction; names are printed as recorded.
 * A figure the database does not hold is null, and the template prints a dash rather
 * than a guess.
 */
final class ResultDocumentData
{
    /**
     * @return array{karyavahi: array<string, mixed>, sealed_covers: list<array<string, mixed>>}
     */
    public function build(DpcProceeding $proceeding): array
    {
        /** @var DpcProposal $proposal */
        $proposal = $proceeding->proposal()->with(['department', 'promotionFromPost', 'promotionToPost', 'recruitmentRule'])->firstOrFail();
        $run = $proceeding->finalCalculationRun;
        if (! $run instanceof DpcCalculationRun) {
            throw new WorkflowException('The result is not approved: there is no final calculation to make its documents from.');
        }

        $results = DpcCalculationResult::query()->where('dpc_calculation_run_id', $run->id)->orderBy('sn')->get();
        $holds = DpcProceedingHold::query()->where('dpc_proceeding_id', $proceeding->id)->active()->orderBy('sn')->get()->groupBy('sn');

        $schedule = $proceeding->schedule ?? app(CommitteeSittingService::class)->schedule($proposal);
        $from = $proposal->promotionFromPost;
        $to = $proposal->promotionToPost;
        $fromClass = $from?->class_level?->value;
        $toClass = $to?->class_level?->value;

        $head = [
            'date' => $schedule?->scheduledFor()->format('d.m.Y'),
            'department' => (string) ($proposal->department->name ?? ''),
            'from_post' => (string) ($from->name ?? ''),
            'to_post' => (string) ($to->name ?? ''),
            'dpc_code' => (string) ($proposal->dpc_code ?? $proposal->citation()),
        ];

        $karyavahi = $head + [
            'format' => $fromClass === 'I' && $toClass === 'I' ? 'class_1_to_1' : 'other',
            'pair' => ($fromClass ?? '?').'-'.($toClass ?? '?'),
            'members' => $this->members($proposal),
            'recruitment_order' => $proposal->recruitmentRule?->government_order_number,
            'legal_stay' => (bool) $proposal->legal_stay,
            'seniority_as_on' => $proposal->seniority_list_as_on?->format('d.m.Y'),
            'zone' => $this->zone($run),
            'qualifying_as_on' => '01.01.'.(int) $proposal->dpc_year,
            'qualifying_years' => $proposal->promotion_duration_years,
            'vacancies' => $this->vacancies($proposal),
            'previous_dpc_year' => $this->previousDpcYear($proposal),
            'criteria' => $this->criteria($run, $fromClass, $toClass, (int) $proposal->dpc_year),
            'recommendations' => $results->filter(fn (DpcCalculationResult $r): bool => $r->in_zone)
                ->map(fn (DpcCalculationResult $r): array => ['sn' => $r->sn, 'name' => $r->officer_name, 'recommendation' => $this->recommendation($r, $holds->has($r->sn))])->values()->all(),
            'selection_year' => $proceeding->promotion_as_on_year ?? (int) $proposal->dpc_year,
            'selection' => $results->filter(fn (DpcCalculationResult $r): bool => $r->list_type === ListType::Main)
                ->sortBy('list_position')->map(fn (DpcCalculationResult $r): array => ['sn' => $r->sn, 'name' => $r->officer_name])->values()->all(),
            'unfit' => $results->filter(fn (DpcCalculationResult $r): bool => $r->in_zone && in_array($r->status(), [RemarkCode::NotEligible, RemarkCode::Rejected], true) && ! $holds->has($r->sn))
                ->map(fn (DpcCalculationResult $r): array => ['sn' => $r->sn, 'name' => $r->officer_name])->values()->all(),
            'sealed' => $results->filter(fn (DpcCalculationResult $r): bool => $r->status() === RemarkCode::SealedCover)
                ->map(fn (DpcCalculationResult $r): array => ['sn' => $r->sn, 'name' => $r->officer_name])->values()->all(),
            'circulation' => $holds->map(fn (Collection $held, int|string $sn): array => [
                'sn' => (int) $sn,
                'name' => (string) $held->first()?->officer_name,
                'reasons' => $held->map(fn (DpcProceedingHold $h): string => $this->holdReason($h))->unique()->values()->all(),
            ])->values()->all(),
            'not_considered' => $results->reject(fn (DpcCalculationResult $r): bool => $r->in_zone)
                ->map(fn (DpcCalculationResult $r): array => ['sn' => $r->sn, 'name' => $r->officer_name, 'reason' => 'विचार क्षेत्र से बाहर'])->values()->all(),
        ];
        // Kandika 14 (vacancies carried forward) is not recorded anywhere, and deriving it
        // (vacancies less the selection list) would decide how a seat held for a sealed
        // cover or a circulation case counts — a business rule nobody has given. Pending
        // clarification (2026-11-26): the document prints a dash.
        $karyavahi['carried_forward'] = null;

        $sealedCovers = [];
        foreach ($results->filter(fn (DpcCalculationResult $r): bool => $r->status() === RemarkCode::SealedCover) as $result) {
            $sealedCovers[] = $head + [
                'result_id' => $result->id,
                'sn' => $result->sn,
                'name' => $result->officer_name,
                'members' => $karyavahi['members'],
                'reason' => $this->sealedReason($result),
                'circulation' => $holds->get($result->sn, collect())->map(fn (DpcProceedingHold $h): string => $this->holdReason($h))->unique()->values()->all(),
            ];
        }

        return ['karyavahi' => $karyavahi, 'sealed_covers' => $sealedCovers];
    }

    /**
     * The committee as it sat: every serving seat but the CGPSC Chairman's own (that
     * office allots; the allottee presides), the DPC Chairman first.
     *
     * @return list<array{user_id: int|null, name: string, designation: string, chairman: bool, represents: list<string>}>
     */
    public function members(DpcProposal $proposal): array
    {
        $covers = app(SealedCoverService::class);

        // The DPC Chairman presides, seat or none (2026-11-17): one who holds no seat
        // heads the attendance and signs like every member.
        $unseated = app(SittingMeetingService::class)->unseatedDpcChairman($proposal);
        $chairman = $unseated === null ? [] : [[
            'user_id' => $unseated->id,
            'name' => (string) $unseated->name,
            'designation' => match (true) {
                $unseated->hasRole(Role::Chairman->value) => 'अध्यक्ष, छत्तीसगढ़ लोक सेवा आयोग',
                $unseated->hasRole(Role::Member->value) => 'सदस्य, छत्तीसगढ़ लोक सेवा आयोग',
                default => '',
            },
            'chairman' => true,
            'represents' => [],
        ]];

        return [...$chairman, ...DpcCommitteeMember::query()->where('dpc_proposal_id', $proposal->id)->serving()
            ->where('capacity', '!=', CommitteeCapacity::CgpscChairman->value)
            ->orderBy('sort_order')->get()
            ->map(function (DpcCommitteeMember $seat) use ($covers, $proposal): array {
                $user = $seat->user_id === null ? null : User::query()->find($seat->user_id);
                $represents = [];
                foreach (['sc' => 'अ.जा. प्रतिनिधि', 'st' => 'अ.ज.जा. प्रतिनिधि', 'obc' => 'अ.पि.वर्ग प्रतिनिधि', 'female' => 'महिला प्रतिनिधि'] as $flag => $label) {
                    if ((bool) $seat->getAttribute('is_'.$flag)) {
                        $represents[] = $label;
                    }
                }

                return [
                    'user_id' => $seat->user_id,
                    'name' => (string) $seat->name,
                    'designation' => (string) ($seat->designation ?? ''),
                    'chairman' => $covers->isDpcChairman($proposal, $user),
                    'represents' => $represents,
                ];
            })
            ->sortByDesc('chairman')->values()->all()];
    }

    /** @return array{size: int|null, vacancies: int|null, formula: string|null} */
    private function zone(DpcCalculationRun $run): array
    {
        $zone = (array) $run->zone;

        return [
            'size' => isset($zone['effective_size']) ? (int) $zone['effective_size'] : (isset($zone['size']) ? (int) $zone['size'] : null),
            'vacancies' => isset($zone['vacancies']) ? (int) $zone['vacancies'] : null,
            'formula' => isset($zone['formula']) ? (string) $zone['formula'] : null,
        ];
    }

    /**
     * From the Vacancy Master record the DPC was proposed on.
     *
     * @return array{clear: int|null, arising: int|null, total: int|null, promotion_total: int|null, period_to: string|null, entries: list<array<string, mixed>>, sanctioned: int|null, percentage: string|null, promotion_posts: int|null, filled: int|null}
     */
    private function vacancies(DpcProposal $proposal): array
    {
        $position = app(VacancyMasterService::class)->finalizedForProposal($proposal);
        $b = $position === null ? null : ($position->breakdown ?? app(VacancyMasterService::class)->breakdown($position));

        if (! is_array($b)) {
            return ['clear' => null, 'arising' => null, 'total' => null, 'promotion_total' => null, 'period_to' => null, 'entries' => [],
                'sanctioned' => $proposal->sanctioned_post_count, 'percentage' => null, 'promotion_posts' => null, 'filled' => null];
        }

        $clear = isset($b['clear_vacancy']) ? (int) $b['clear_vacancy'] : null;
        $total = isset($b['total_vacancy']) ? (int) $b['total_vacancy'] : null;
        $sanctioned = isset($b['sanctioned']) ? (int) $b['sanctioned'] : $proposal->sanctioned_post_count;
        $percentage = isset($b['promotion_percentage']) ? (string) $b['promotion_percentage'] : null;

        return [
            'clear' => $clear,
            'arising' => $clear === null || $total === null ? null : $total - $clear,
            'total' => $total,
            'promotion_total' => isset($b['final_quota']) ? (int) $b['final_quota'] : $total,
            'period_to' => isset($b['period_to']) ? Carbon::parse((string) $b['period_to'])->format('d.m.Y') : null,
            'entries' => collect((array) ($b['entries'] ?? []))
                ->filter(fn ($e): bool => is_array($e) && ($e['counted'] ?? true))
                ->map(fn (array $e): array => [
                    'quota' => $this->quota($e['uuid'] ?? null),
                    'since' => isset($e['effective_date']) ? Carbon::parse((string) $e['effective_date'])->format('d.m.Y') : null,
                    'reason' => ($reason = VacancyReason::tryFrom((string) ($e['reason'] ?? ''))) === null ? '—' : (string) __('vacancymaster.reasons.'.$reason->value, [], 'hi'),
                    'officer' => $e['officer_name'] ?? null,
                    'count' => (int) ($e['count'] ?? 0),
                ])->values()->all(),
            'sanctioned' => $sanctioned,
            'percentage' => $percentage,
            'promotion_posts' => app(VacancyEngine::class)->quotaPosition($sanctioned, $percentage, 0, 0)['promotion_chairs'],
            'filled' => isset($b['working']['total']) ? (int) $b['working']['total'] : null,
        ];
    }

    /** The quota of the vacated post, as the Vacancy Master entry records it. */
    private function quota(mixed $entryUuid): ?string
    {
        $quota = is_string($entryUuid) ? VacancyPositionEntry::query()->where('uuid', $entryUuid)->value('occupant_quota') : null;

        return in_array($quota, ['promotion', 'direct'], true) ? (string) __('vacancymaster.quota.'.$quota, [], 'hi') : null;
    }

    private function previousDpcYear(DpcProposal $proposal): ?int
    {
        $held = DpcProposalVerification::query()->where('dpc_proposal_id', $proposal->id)
            ->whereNotNull('previous_dpc_held_on')->latest('id')->value('previous_dpc_held_on');

        return $held === null ? null : Carbon::parse((string) $held)->year;
    }

    /**
     * The minimum criteria the calculation applied, from its own rule snapshot.
     *
     * @return array{years: int, qualifying_total: int|null, disqualifying_grade: string, last_year_mandatory: bool, last_year_min_grade: string|null}
     */
    private function criteria(DpcCalculationRun $run, ?string $fromClass, ?string $toClass, int $dpcYear): array
    {
        $snapshot = (array) $run->configuration_snapshot;
        $rule = is_array($snapshot['class_rule'] ?? null) ? $snapshot['class_rule'] : null;
        $marks = array_map('intval', (array) ($snapshot['cr_grade_marks'] ?? []));
        $pair = ($fromClass ?? '').'-'.($toClass ?? '');

        $minLast = $rule['minimum_by_position'][1] ?? $rule['minimum_by_year'][$dpcYear - 1] ?? null;
        $gradeFor = function (?int $points) use ($marks): ?string {
            if ($points === null) {
                return null;
            }
            $at = array_filter($marks, fn (int $p): bool => $p >= $points);
            asort($at);

            return array_key_first($at);
        };

        return [
            'years' => (int) ($rule['cr_years'] ?? $snapshot['scoring_years'] ?? 5),
            'qualifying_total' => isset($rule['qualifying_total']) ? (int) $rule['qualifying_total'] : (isset($snapshot['qualifying_scores'][$pair]) ? (int) $snapshot['qualifying_scores'][$pair] : null),
            'disqualifying_grade' => (string) ($snapshot['gates']['disqualifying_grade'] ?? 'घ'),
            'last_year_mandatory' => in_array(1, (array) ($rule['mandatory_positions'] ?? []), true) || in_array($dpcYear - 1, array_map('intval', (array) ($rule['mandatory_years'] ?? [])), true),
            'last_year_min_grade' => $gradeFor($minLast === null ? null : (int) $minLast),
        ];
    }

    private function recommendation(DpcCalculationResult $result, bool $held): string
    {
        if ($result->status() === RemarkCode::SealedCover) {
            return 'अनुशंसा बंद लिफाफे में';
        }
        if ($held) {
            return 'परिभ्रमण में';
        }

        return match (true) {
            $result->list_type === ListType::Main => 'पदोन्नति हेतु उपयुक्त',
            $result->list_type === ListType::Waiting => 'पदोन्नति हेतु उपयुक्त (प्रतीक्षा सूची)',
            in_array($result->status(), [RemarkCode::NotEligible, RemarkCode::Rejected], true) => 'अनुपयुक्त',
            default => 'उपयुक्त, किंतु रिक्ति उपलब्ध न होने से चयन सूची में नहीं',
        };
    }

    private function holdReason(DpcProceedingHold $hold): string
    {
        $label = (string) __('proceeding.result_documents.hold_reasons.'.$hold->reason_code->value, [], 'hi');

        return trim($label.($hold->detail ? ' — '.$hold->detail : ''));
    }

    private function sealedReason(DpcCalculationResult $result): string
    {
        $row = $result->dpc_evaluation_row_id === null ? null : DpcEvaluationRow::query()->with('sealedCoverReason')->find($result->dpc_evaluation_row_id);

        if ($row?->enquiry_ongoing === true) {
            return 'आरोप पत्र जारी होकर विभागीय जांच / न्यायालयीन प्रकरण प्रचलित';
        }

        $reason = $row?->sealedCoverReason?->name_hi;

        return $reason !== null && trim((string) $reason) !== '' ? (string) $reason : 'विभागीय जांच प्रकरण लंबित';
    }
}
