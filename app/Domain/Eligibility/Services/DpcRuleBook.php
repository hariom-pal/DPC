<?php

declare(strict_types=1);

namespace App\Domain\Eligibility\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Eligibility\Models\CrScoringScale;
use App\Domain\Eligibility\Models\PhReservationRule;
use App\Domain\Eligibility\RuleSet;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Domain\Shared\Workflow\WorkflowException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Which CR scale, qualifying scores and PH reservation govern a DPC.
 *
 * The Administrator records them version by version (rules 2.1, 3.4, 3.5 — "effective
 * as per Master data"), each from a DPC year onwards and on an order with its PDF. A
 * DPC is judged by the latest version whose first year is not after its DPC year (the
 * DPC year, or the proposal's year when none is stated). With no version recorded the
 * built-in rules of `config/dpc.php` apply — the behaviour before these masters
 * existed — so installing them changes no result.
 *
 * Versions are never edited or deleted. Two versions may name the same first year;
 * the later recorded one wins, and the earlier stays in the history.
 */
final class DpcRuleBook
{
    public function __construct(private readonly ActivityTimelineService $timeline) {}

    public function yearOf(DpcProposal $proposal): int
    {
        return (int) ($proposal->dpc_year ?: $proposal->proposal_date->year);
    }

    // ------------------------------------------------------------------ CR scale

    public function crScaleFor(int $year): ?CrScoringScale
    {
        return CrScoringScale::query()
            ->where('effective_from_year', '<=', $year)
            ->orderByDesc('effective_from_year')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The rule set a DPC is calculated with: configuration, with the in-force scale's
     * marks, gates and qualifying scores over it. The version names the scale, so the
     * run's snapshot records which one it was.
     */
    public function ruleSetFor(DpcProposal $proposal): RuleSet
    {
        $scale = $this->crScaleFor($this->yearOf($proposal));

        if ($scale === null) {
            return RuleSet::fromConfig();
        }

        /** @var array<string, mixed> $scoring */
        $scoring = config('dpc.scoring');

        return RuleSet::fromSnapshot([
            ...$scoring,
            'rule_version' => $scoring['rule_version'].'+cr-scale:'.$scale->uuid,
            'cr_grade_marks' => $scale->grade_marks,
            'scoring_years' => $scale->scoring_years,
            'tribal_area_bonus' => $scale->tribal_area_bonus,
            'gates' => [
                'disqualifying_grade' => $scale->disqualifying_grade,
                'last_year_forbidden_grade' => $scale->last_year_forbidden_grades,
            ],
            'qualifying_scores' => $scale->qualifying_scores,
            'super_seat' => [...(array) ($scoring['super_seat'] ?? []), 'required_grade' => $scale->super_seat_grade],
        ]);
    }

    /**
     * Grade => marks for a DPC, for averaging several reports of one year.
     *
     * @return array<string, int>
     */
    public function gradeMarksFor(DpcProposal $proposal): array
    {
        return $this->ruleSetFor($proposal)->crGradeMarks;
    }

    /**
     * The scale in force for a year, as the form and the screen show it — the
     * recorded version, or the built-in rules.
     *
     * @return array{source: CrScoringScale|null, grade_marks: array<string, int>, scoring_years: int, tribal_area_bonus: int, disqualifying_grade: string, last_year_forbidden_grades: array<string, string>, super_seat_grade: string, qualifying_scores: array<string, int>}
     */
    public function crTermsFor(int $year): array
    {
        $scale = $this->crScaleFor($year);

        if ($scale !== null) {
            return [
                'source' => $scale,
                'grade_marks' => $scale->grade_marks,
                'scoring_years' => $scale->scoring_years,
                'tribal_area_bonus' => $scale->tribal_area_bonus,
                'disqualifying_grade' => $scale->disqualifying_grade,
                'last_year_forbidden_grades' => $scale->last_year_forbidden_grades,
                'super_seat_grade' => $scale->super_seat_grade,
                'qualifying_scores' => $scale->qualifying_scores,
            ];
        }

        $rules = RuleSet::fromConfig();

        return [
            'source' => null,
            'grade_marks' => $rules->crGradeMarks,
            'scoring_years' => $rules->scoringYears,
            'tribal_area_bonus' => $rules->tribalAreaBonus,
            'disqualifying_grade' => $rules->disqualifyingGrade,
            'last_year_forbidden_grades' => $rules->lastYearForbiddenGrades,
            'super_seat_grade' => $rules->superSeatGrade,
            'qualifying_scores' => $rules->qualifyingScores,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordCrScale(array $data, UploadedFile $document, User $actor): CrScoringScale
    {
        return DB::transaction(function () use ($data, $document, $actor): CrScoringScale {
            $scale = CrScoringScale::query()->create([
                'name' => $data['name'],
                'effective_from_year' => (int) $data['effective_from_year'],
                'grade_marks' => array_map('intval', $data['grade_marks']),
                'scoring_years' => (int) $data['scoring_years'],
                'tribal_area_bonus' => (int) $data['tribal_area_bonus'],
                'disqualifying_grade' => $data['disqualifying_grade'],
                'last_year_forbidden_grades' => array_filter((array) ($data['last_year_forbidden_grades'] ?? []), fn ($g): bool => $g !== null && $g !== ''),
                'super_seat_grade' => $data['super_seat_grade'],
                'qualifying_scores' => array_map('intval', $data['qualifying_scores']),
                'order_number' => $data['order_number'],
                'order_date' => $data['order_date'],
                'remarks' => $data['remarks'] ?? null,
                ...$this->store($document, 'cr-scales'),
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);

            $this->timeline->record('dpc_rules.cr_scale_recorded', $scale, __('dpcrules.timeline.cr_scale_recorded', [
                'name' => $scale->name,
                'year' => $scale->effective_from_year,
            ]), ['order_number' => $scale->order_number], $actor);

            return $scale;
        });
    }

    // ------------------------------------------------------------------ PH

    public function phRuleFor(int $year): ?PhReservationRule
    {
        return PhReservationRule::query()
            ->where('effective_from_year', '<=', $year)
            ->orderByDesc('effective_from_year')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{source: PhReservationRule|null, percentage: string, rounding: string}
     */
    public function phTermsFor(int $year): array
    {
        $rule = $this->phRuleFor($year);

        return [
            'source' => $rule,
            'percentage' => $rule === null
                ? number_format((float) config('dpc.employee_details.ph_quota_percent', 3), 2, '.', '')
                : (string) $rule->percentage,
            'rounding' => $rule === null ? 'floor' : $rule->rounding,
        ];
    }

    /**
     * Posts reserved for PH out of `$vacancies`, in whole numbers.
     *
     * Integer arithmetic on hundredths of a percent, so 3% of 100 is 3 and never
     * 2.9999…, and the rule's "even .9 is disregarded" is exact.
     */
    public function phPosts(int $vacancies, string $percentage, string $rounding): int
    {
        $hundredths = (int) round(((float) $percentage) * 100);
        $scaled = $vacancies * $hundredths;     // posts × 10 000

        return match ($rounding) {
            'ceil' => intdiv($scaled + 9999, 10000),
            'round' => intdiv($scaled + 5000, 10000),
            default => intdiv($scaled, 10000),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordPhRule(array $data, UploadedFile $document, User $actor): PhReservationRule
    {
        return DB::transaction(function () use ($data, $document, $actor): PhReservationRule {
            $rule = PhReservationRule::query()->create([
                'effective_from_year' => (int) $data['effective_from_year'],
                'percentage' => $data['percentage'],
                'rounding' => $data['rounding'],
                'order_number' => $data['order_number'],
                'order_date' => $data['order_date'],
                'remarks' => $data['remarks'] ?? null,
                ...$this->store($document, 'ph-reservation'),
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);

            $this->timeline->record('dpc_rules.ph_rule_recorded', $rule, __('dpcrules.timeline.ph_rule_recorded', [
                'percentage' => $rule->percentage,
                'year' => $rule->effective_from_year,
            ]), ['order_number' => $rule->order_number], $actor);

            return $rule;
        });
    }

    // ------------------------------------------------------------------ internals

    /**
     * @return array{disk: string, path: string, original_name: string, size_bytes: int, sha256: string}
     */
    private function store(UploadedFile $file, string $folder): array
    {
        $disk = (string) config('dpc.documents.disk', 'local');
        $path = Storage::disk($disk)->putFile('private/dpc-rules/'.$folder, $file);

        if ($path === false) {
            throw new WorkflowException(__('dpcrules.errors.store_failed'), 'document');
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'size_bytes' => $file->getSize() ?: 0,
            'sha256' => hash_file('sha256', (string) $file->getRealPath()) ?: '',
        ];
    }
}
