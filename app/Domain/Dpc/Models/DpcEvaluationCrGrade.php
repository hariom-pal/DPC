<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Models;

use App\Domain\Master\Models\IntegrityOption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One year's CR grade for an evaluation row (PPT S18, per-year layout per
 * decision Q4) — M4 Phase 5.
 *
 * `grade` is nullable: a Circulation DPC exists precisely because an officer
 * is missing CR years, so "this year is on the chart but no grade was
 * recorded" must be representable. The grade -> mark conversion is M5's.
 *
 * @property int $id
 * @property int $dpc_evaluation_row_id
 * @property int $year
 * @property string|null $grade
 * @property bool|null $tribal_area
 * @property bool|null $put_up
 * @property bool|null $has_grading
 * @property list<array<string, string|null>>|null $reports
 * @property int|null $integrity_option_id
 * @property string|null $integrity_departmental_view
 * @property-read IntegrityOption|null $integrityOption
 */
class DpcEvaluationCrGrade extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'dpc_evaluation_row_id',
        'year',
        'grade',

        /*
         | Whether the officer served in a tribal area during *this* CR year
         | (change register of 2026-08-27, item 18).
         |
         | Recorded so the benefit is attributable to its own year and cannot read as
         | applying to another. **It does not feed the score**: `ScoringService`
         | applies PPT §5.2's single flat bonus from the officer-level total, and item
         | 21 forbids modifying that formula. BD-36.
         */
        'tribal_area',

        /*
         | The CR questionnaire of the Coder Instructions, page 3: was a CR put up for
         | the year, does it carry a grading, and the reports written in it (at most
         | four, each with the reporting, reviewing and accepting officers'
         | designations and grades). `grade` stays the one value the engine reads.
         */
        'put_up',
        'has_grading',
        'reports',
        // Integrity (सत्यनिष्ठा) of the year, from the Administrator's master (2026-11-14).
        'integrity_option_id',
        // 2026-11-16: 'certified' or 'not_certified', where the answer asks for it.
        'integrity_departmental_view',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'tribal_area' => 'boolean',
            'put_up' => 'boolean',
            'has_grading' => 'boolean',
            'reports' => 'array',
        ];
    }

    /**
     * The grades this year's CRs carry: each report's highest available authority
     * (Accepting, else Reviewing, else Reporting); a year entered on the Evaluation
     * Chart, without reports, its own grade.
     *
     * @return list<string>
     */
    public function finalGrades(): array
    {
        $grades = array_values(array_filter(array_map(
            static fn (array $report): ?string => self::finalGradeOf($report),
            (array) $this->reports,
        )));

        return $grades === [] && $this->grade !== null && $this->grade !== '' ? [$this->grade] : $grades;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function finalGradeOf(array $report): ?string
    {
        // The one-CR form (2026-11-01) records the highest authority's grade itself.
        if (isset($report['grade']) && $report['grade'] !== '') {
            return (string) $report['grade'];
        }

        foreach (['accepting_grade', 'reviewer_grade', 'reporter_grade'] as $role) {
            if (($report[$role] ?? null) !== null && $report[$role] !== '') {
                return $report[$role];
            }
        }

        return null;
    }

    /**
     * The year's points on the Eligible CR Grades (rule 3.4): its one graded CR's, or
     * for several the points of the nearest grade at or below their average (MoM of
     * 12 November 2026). Null when the year carries no grade, or a grade the points do
     * not name.
     *
     * @param  array<string, int>  $points  grade code => points
     */
    public function pointsOn(array $points): ?int
    {
        $grade = self::yearGradeOf($this->finalGrades(), $points);

        return $grade === null ? null : (int) $points[$grade];
    }

    /**
     * The year's grade from its CRs' grades (MoM of 12 November 2026): the one grade,
     * or for several the nearest grade at or below their average — never rounded up.
     * Null when there is none, or a grade the points do not name.
     *
     * @param  list<string>  $grades
     * @param  array<string, int>  $points  grade code => points
     */
    public static function yearGradeOf(array $grades, array $points): ?string
    {
        if ($grades === []) {
            return null;
        }

        foreach ($grades as $grade) {
            if (! isset($points[$grade])) {
                return null;
            }
        }

        if (count($grades) === 1) {
            return $grades[0];
        }

        $average = array_sum(array_map(fn (string $grade): int => (int) $points[$grade], $grades)) / count($grades);
        $below = array_filter($points, fn ($value): bool => (int) $value <= $average);
        arsort($below);

        $grade = array_key_first($below);

        return $grade === null ? null : (string) $grade;
    }

    /**
     * @return BelongsTo<IntegrityOption, $this>
     */
    public function integrityOption(): BelongsTo
    {
        return $this->belongsTo(IntegrityOption::class)->withTrashed();
    }

    /**
     * @return BelongsTo<DpcEvaluationRow, $this>
     */
    public function row(): BelongsTo
    {
        return $this->belongsTo(DpcEvaluationRow::class, 'dpc_evaluation_row_id');
    }

    public const VIEW_CERTIFIED = 'certified';

    public const VIEW_NOT_CERTIFIED = 'not_certified';

    /**
     * Whether the year's Integrity makes the officer Fit (2026-11-16): the Final
     * Departmental View where the answer asks for one, the answer's own result
     * otherwise. Null: no answer, or the view not yet recorded.
     */
    public function integrityFit(): ?bool
    {
        $option = $this->integrityOption;

        if ($option === null) {
            return null;
        }

        if ($option->needs_departmental_view) {
            return match ($this->integrity_departmental_view) {
                self::VIEW_CERTIFIED => true,
                self::VIEW_NOT_CERTIFIED => false,
                default => null,
            };
        }

        return (bool) $option->fit;
    }
}
