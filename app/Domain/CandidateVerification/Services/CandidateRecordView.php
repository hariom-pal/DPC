<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Services;

use App\Domain\CandidateVerification\Enums\VerificationSection;
use App\Domain\Dpc\Models\DpcEvaluationRecordGap;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use App\Domain\Dpc\Services\PromotionRuleCheck;

/**
 * What a candidate sees of their own record, section by section (2026-11-19), read
 * from the records the department keeps and the services that already judge them —
 * the CR window, the rule check's score, the Property File window. Nothing is
 * computed anew and nothing written. The same arrays are what the candidate signs,
 * so the screen and the signed snapshot cannot differ.
 *
 * Another officer appears only in the Seniority List, with what that list publishes:
 * the seniority number, the name, the category and the current post.
 */
final class CandidateRecordView
{
    public function __construct(
        private readonly EmployeeDetailsService $details,
        private readonly PromotionRuleCheck $ruleCheck,
    ) {}

    /** @return array<string, mixed> */
    public function section(DpcEvaluationRow $row, VerificationSection $section): array
    {
        $row->loadMissing(['proposal.department', 'proposal.promotionToPost', 'proposal.promotionFromPost', 'reservationCategory', 'crGrades.integrityOption', 'propertyFlags', 'recordGaps.reason']);

        return match ($section) {
            VerificationSection::Seniority => $this->seniority($row),
            VerificationSection::Cr => $this->cr($row),
            VerificationSection::Integrity => $this->integrity($row),
            VerificationSection::Ipr => $this->ipr($row),
        };
    }

    /** @return array<string, array<string, mixed>> every section, keyed by its value */
    public function all(DpcEvaluationRow $row): array
    {
        $sections = [];
        foreach (VerificationSection::cases() as $section) {
            $sections[$section->value] = $this->section($row, $section);
        }

        return $sections;
    }

    /** @return array<string, mixed> */
    private function seniority(DpcEvaluationRow $row): array
    {
        $proposal = $row->proposal;
        $list = $proposal->evaluationRows()->with('reservationCategory')->orderBy('sn')->get();
        $position = $list->search(fn (DpcEvaluationRow $other): bool => $other->id === $row->id);

        return [
            'own' => [
                'name' => $row->name,
                'employee_number' => $row->employee_number,
                'dob' => $row->dob->format('d-m-Y'),
                // No gender is recorded on the Seniority List or the employee details.
                'gender' => null,
                'mobile' => $row->mobile,
                'department' => $proposal->department?->name,
                'designation' => $row->promotion_from_post,
                'date_of_joining_service' => $row->date_of_joining_service?->format('d-m-Y'),
                'date_of_joining_current_post' => $row->date_of_posting_on_promotion_from_post?->format('d-m-Y'),
                // The Seniority List records the posting date, not a separate appointment date.
                'date_of_appointment_current_post' => null,
                'seniority_number' => $row->sn,
                'seniority_position' => $position === false ? null : $position + 1,
                'category' => $row->reservationCategory?->code,
                'qualification' => $row->qualification,
            ],
            'dpc' => [
                'reference' => (string) ($proposal->dpc_code ?? $proposal->citation()),
                'year' => (int) ($proposal->dpc_year ?: $proposal->proposal_date->year),
                'department' => $proposal->department?->name,
                'from_post' => $proposal->promotionFromPost?->name,
                'promotion_post' => $proposal->promotionToPost?->name,
                'seniority_list_size' => $list->count(),
                // 2026-11-22: the list CGPSC published, entered by the Administrator.
                'cgpsc_seniority_list_url' => $proposal->cgpsc_seniority_list_url ?: null,
            ],
            // Every officer's basic details (2026-11-20, spec item 5): the joining dates with
            // the name, category and post. A date of birth only on the candidate's own row;
            // nobody's mobile, CR, Integrity or Property File.
            'list' => $list->map(fn (DpcEvaluationRow $other): array => [
                'sn' => $other->sn,
                'name' => $other->name,
                'category' => $other->reservationCategory?->code,
                'current_post' => $other->promotion_from_post,
                'date_of_joining_service' => $other->date_of_joining_service?->format('d-m-Y'),
                'date_of_joining_current_post' => $other->date_of_posting_on_promotion_from_post?->format('d-m-Y'),
                'dob' => $other->id === $row->id ? $other->dob->format('d-m-Y') : null,
                'is_self' => $other->id === $row->id,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function cr(DpcEvaluationRow $row): array
    {
        $window = $this->details->crWindow($row, $row->crGrades);
        $gaps = $row->recordGaps->where('kind', DpcEvaluationRecordGap::KIND_CR)->keyBy('year');
        $appointed = $row->appointedYear();
        $years = array_values(array_unique([...$window->years, ...$row->crGrades->pluck('year')->map(fn ($y): int => (int) $y)->all()]));
        rsort($years);

        $lines = [];
        foreach ($years as $year) {
            $cr = $row->crGrades->firstWhere('year', $year);
            $gap = $gaps->get($year);
            $lines[] = [
                'year' => $year,
                'state' => match (true) {
                    $cr === null => 'not_recorded',
                    $cr->put_up === false => 'missing',
                    $cr->has_grading === false => 'not_graded',
                    default => 'graded',
                },
                'grade' => $cr?->put_up !== false && $cr?->has_grading !== false ? $cr?->grade : null,
                'tribal_area' => $cr?->tribal_area === true,
                'missing_reason' => $gap?->reason?->label(),
                'counted' => in_array($year, $window->counted, true),
                'skipped' => in_array($year, $window->skipped, true),
                'before_appointment' => $row->isBeforeAppointment($year),
            ];
        }

        $score = $this->ruleCheck->score($row, $row->proposal);

        return [
            'years' => $lines,
            // 2026-11-22: the candidate sees which CR years are missing, and why.
            'missing' => $this->details->missingCrYears($row),
            'appointed_year' => $appointed,
            'score' => $score === null ? null : [
                'years_used' => $score->yearsUsed,
                'marks_by_year' => $score->marksByYear,
                'average_filled_years' => $score->averageFilledYears,
                'average_fill_mark' => $score->averageFillMark,
                'average_contribution' => $score->averageFilledYears * (int) $score->averageFillMark,
                'tribal_bonus' => $score->tribalAreaBonus,
                'total' => $score->total,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function integrity(DpcEvaluationRow $row): array
    {
        $lines = [];
        foreach ($row->crGrades->sortByDesc('year') as $cr) {
            if ($cr->put_up === false) {
                continue;
            }

            $lines[] = [
                'year' => (int) $cr->year,
                'answer' => $cr->integrityOption?->label(),
                'departmental_view' => $cr->integrity_departmental_view,
                'fit' => $cr->integrityFit(),
            ];
        }

        return ['years' => $lines];
    }

    /** @return array<string, mixed> */
    private function ipr(DpcEvaluationRow $row): array
    {
        $gaps = $row->recordGaps->where('kind', DpcEvaluationRecordGap::KIND_PROPERTY)->keyBy('year');
        $years = array_values(array_unique([...$this->details->propertyYearWindow($row), ...$row->propertyFlags->pluck('year')->map(fn ($y): int => (int) $y)->all()]));
        rsort($years);

        $lines = [];
        foreach ($years as $year) {
            $flag = $row->propertyFlags->firstWhere('year', $year);
            $gap = $gaps->get($year);
            $lines[] = [
                'year' => $year,
                'state' => match (true) {
                    $flag === null => 'not_recorded',
                    $flag->submitted_on !== null => 'filed',
                    default => 'missing',
                },
                'filed_on' => $flag?->submitted_on?->format('d-m-Y'),
                'late' => $flag !== null && $flag->submitted_on !== null && $flag->isLate(),
                'missing_reason' => $gap?->reason?->label(),
            ];
        }

        return ['years' => $lines];
    }

    /**
     * The years a grievance on this section may name; none for the Seniority section.
     *
     * @return list<int>
     */
    public function yearsFor(DpcEvaluationRow $row, VerificationSection $section): array
    {
        $data = $this->section($row, $section);

        return array_values(array_map(fn (array $line): int => (int) $line['year'], $data['years'] ?? []));
    }

    /** Worked out from the record, never a value the department entered. */
    private const DERIVED = ['counted', 'skipped', 'before_appointment', 'is_self', 'seniority_position', 'years_used', 'marks_by_year', 'average_filled_years', 'average_fill_mark', 'average_contribution', 'tribal_bonus'];

    /**
     * What changed in one section, value by value, as "label: before → after"
     * (2026-11-25): the old and the corrected value of a grievance correction. A
     * year's lines are matched by their year.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public static function changes(array $before, array $after): array
    {
        $old = self::flatten($before);
        $new = self::flatten($after);
        $changes = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            $from = $old[$key] ?? null;
            $to = $new[$key] ?? null;
            if ($from !== $to) {
                $changes[] = sprintf('%s: %s → %s', $key, self::shown($from), self::shown($to));
            }
        }

        return $changes;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, scalar|null>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            if (in_array((string) $key, self::DERIVED, true) || $key === 'list') {
                continue;
            }
            // A year's line is named by its year, not by where it sits in the list.
            $name = is_array($value) && isset($value['year']) && is_int($key) ? (string) $value['year'] : (string) $key;
            $path = $prefix === '' ? $name : $prefix.' '.$name;
            if (is_array($value)) {
                $flat += self::flatten($value, $path);
            } elseif ($key !== 'year') {
                $flat[$path] = is_bool($value) ? ($value ? 'yes' : 'no') : $value;
            }
        }

        return $flat;
    }

    private static function shown(mixed $value): string
    {
        return $value === null || $value === '' ? '—' : (string) $value;
    }

    /** The DPC reference shown to the candidate. */
    public static function reference(DpcProposal $proposal): string
    {
        return (string) ($proposal->dpc_code ?? $proposal->citation());
    }
}
