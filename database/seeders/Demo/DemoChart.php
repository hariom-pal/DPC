<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Master\Models\ReservationCategory;
use Illuminate\Support\Carbon;

/**
 * Turns a compact officer specification into the three officer sections a DPC
 * proposal carries: the Seniority List (S17), the Evaluation / Tabulation
 * Chart (S18), and the Working Officers list (S16).
 *
 * The seniority list and the chart are built from the **same** officers,
 * because that is how a real proposal works — the chart is the seniority list
 * with CR, integrity and immovable-property returns against each name. Seeding
 * them from separate lists would produce a proposal no department could have
 * produced, and the S17→S18 comparison is one of the screens being shown.
 *
 * Nothing here scores, ranks or judges. It writes exactly what the department
 * would have typed; the M5 engine decides what it means.
 */
final class DemoChart
{
    /**
     * The CR columns the chart carries. Six columns, of which the engine
     * scores the five most recent that carry a grade.
     *
     * @var list<int>
     */
    public const CR_YEARS = [2019, 2020, 2021, 2022, 2023, 2024];

    /** @var array<string, int> */
    private array $categoryIds;

    public function __construct()
    {
        $this->categoryIds = ReservationCategory::query()->pluck('id', 'code')->all();
    }

    /**
     * The Seniority List (S17).
     *
     * @param  list<array<string, mixed>>  $officers
     * @return list<array<string, mixed>>
     */
    public function seniorityEntries(array $officers, string $currentPost, string $feederPost): array
    {
        return array_map(fn (array $officer): array => [
            'seniority_number' => $officer['sn'],
            'employee_number' => $this->employeeNumber($officer),
            'name' => $officer['name'],
            'dob' => $this->dateOfBirth($officer),
            'qualification' => $officer['qualification'] ?? 'M.A., B.Ed.',
            'current_post' => $currentPost,
            'feeder_post' => $feederPost,
            'reservation_category_id' => $this->categoryId($officer['category']),
            'date_of_joining_service' => $this->joinedService($officer),
            'date_of_posting_on_current_post' => $this->postedOnFeederPost($officer),
            'date_of_posting_on_feeder_post' => $this->postedOnFeederPost($officer),
        ], $officers);
    }

    /**
     * The Evaluation / Tabulation Chart (S18), with the per-year CR, integrity
     * and immovable-property returns.
     *
     * @param  list<array<string, mixed>>  $officers
     * @return list<array<string, mixed>>
     */
    public function evaluationRows(array $officers, string $currentPost, string $feederPost): array
    {
        return array_map(function (array $officer) use ($currentPost, $feederPost): array {
            /** @var list<int> $propertyMissing */
            $propertyMissing = $officer['property_missing'] ?? [];

            return [
                'sn' => $officer['sn'],
                'employee_number' => $this->employeeNumber($officer),
                'name' => $officer['name'],
                'dob' => $this->dateOfBirth($officer),
                'qualification' => $officer['qualification'] ?? 'M.A., B.Ed.',
                'current_post' => $currentPost,
                'feeder_post' => $feederPost,
                'reservation_category_id' => $this->categoryId($officer['category']),
                'date_of_joining_service' => $this->joinedService($officer),
                'date_of_posting_on_current_post' => $this->postedOnFeederPost($officer),
                'date_of_posting_on_feeder_post' => $this->postedOnFeederPost($officer),
                'tribal_area_service_years' => $officer['tribal'] ?? 0,
                'note' => $officer['note'] ?? null,
                'cr_grades' => $this->crGrades($officer),
                // Integrity is returned for every year in this data set; the
                // only gap deliberately seeded is the immovable-property one,
                // because that is the ground a Circulation DPC resolves.
                'loyalty_flags' => array_map(
                    static fn (int $year): array => ['year' => $year, 'flag' => true],
                    self::CR_YEARS,
                ),
                'property_flags' => array_map(
                    static fn (int $year): array => [
                        'year' => $year,
                        'flag' => in_array($year, $propertyMissing, true) ? null : true,
                    ],
                    self::CR_YEARS,
                ),
            ];
        }, $officers);
    }

    /**
     * Officers already working on the **target** post (S16) — a different
     * group from the feeder-cadre officers under consideration.
     *
     * @param  list<array<string, mixed>>  $officers
     * @return list<array<string, mixed>>
     */
    public function workingOfficers(array $officers, string $currentPost, string $feederPost): array
    {
        return array_map(fn (array $officer): array => [
            'sn' => $officer['sn'],
            'employee_number' => $this->employeeNumber($officer),
            'name' => $officer['name'],
            'dob' => $this->dateOfBirth($officer),
            'qualification' => $officer['qualification'] ?? 'M.A., B.Ed.',
            'current_post' => $currentPost,
            'feeder_post' => $feederPost,
            'date_of_joining_service' => $this->joinedService($officer),
            'date_of_posting_on_post' => $this->postedOnFeederPost($officer),
            'date_of_posting_on_feeder_post' => $this->postedOnFeederPost($officer),
            'reservation_category_id' => $this->categoryId($officer['category']),
            'roster_position' => $officer['roster'] ?? $officer['sn'],
            'deputation_date' => null,
        ], $officers);
    }

    /**
     * @param  array<string, mixed>  $officer
     * @return list<array{year:int, grade:string|null}>
     */
    private function crGrades(array $officer): array
    {
        /** @var list<string|null> $grades */
        $grades = $officer['grades'];
        $rows = [];

        foreach (self::CR_YEARS as $index => $year) {
            $rows[] = ['year' => $year, 'grade' => $grades[$index] ?? null];
        }

        return $rows;
    }

    private function categoryId(string $code): int
    {
        return $this->categoryIds[$code]
            ?? throw new \RuntimeException("Reservation category [{$code}] is not seeded.");
    }

    /**
     * Deterministic, plausible service dates derived from the serial number, so
     * re-seeding produces the identical case file and a screenshot taken today
     * still matches the data tomorrow.
     *
     * @param  array<string, mixed>  $officer
     */
    private function dateOfBirth(array $officer): string
    {
        $sn = (int) $officer['sn'];

        return Carbon::create(1968 + ($sn % 11), 1 + ($sn * 5 % 12), 1 + ($sn * 7 % 27))->toDateString();
    }

    /**
     * @param  array<string, mixed>  $officer
     */
    private function joinedService(array $officer): string
    {
        $sn = (int) $officer['sn'];

        return Carbon::create(1994 + ($sn % 8), 1 + ($sn * 3 % 12), 1 + ($sn * 11 % 27))->toDateString();
    }

    /**
     * @param  array<string, mixed>  $officer
     */
    private function postedOnFeederPost(array $officer): string
    {
        $sn = (int) $officer['sn'];

        // Seniority on the feeder post follows the serial number: officer 1 was
        // posted first. A seniority list whose dates contradicted its own order
        // would be the first thing a reviewer noticed.
        return Carbon::create(2011, 1, 1)->addMonths($sn * 2)->toDateString();
    }

    /**
     * @param  array<string, mixed>  $officer
     */
    private function employeeNumber(array $officer): string
    {
        return $officer['employee_number'] ?? sprintf('CG%06d', 100000 + (int) $officer['sn'] * 37);
    }
}
