<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Dpc\Models\DpcWorkingOfficer;
use App\Domain\Dpc\Services\VacancyCalculationService;
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

    /**
     * The roster points empty chairs are cycled through, so a padded chart
     * carries a plausible category spread instead of a column of `UR`.
     *
     * Fixed and in this order because re-seeding has to produce the identical
     * case file — a screenshot taken today still has to match the data tomorrow.
     *
     * @var list<string>
     */
    private const VACANT_CHAIR_CATEGORIES = ['UR', 'ST', 'OBC', 'SC'];

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
    public function seniorityEntries(array $officers, string $currentPost, string $promotionFromPost): array
    {
        return array_map(fn (array $officer): array => [
            'seniority_number' => $officer['sn'],
            'employee_number' => $this->employeeNumber($officer),
            'name' => $officer['name'],
            'dob' => $this->dateOfBirth($officer),
            /*
             | Worded the way the **Qualification Master** words it, and that is a
             | statement about the demo rather than about departments.
             |
             | The Recruitment Rule requires a qualification *level* from the master;
             | a real chart carries whatever degree an operator typed, and the two do
             | not compare (BD-39). The demo has to show a journey that reaches a
             | published result, so its data is internally consistent — the advisory
             | path itself is covered by CandidateGateServiceTest rather than by
             | leaving every demo DPC unpublishable.
             */
            'qualification' => $officer['qualification'] ?? 'Post-Graduate with B.Ed.',
            'promotion_to_post' => $currentPost,
            'promotion_from_post' => $promotionFromPost,
            'reservation_category_id' => $this->categoryId($officer['category']),
            'date_of_joining_service' => $this->joinedService($officer),
            'date_of_posting_on_promotion_to_post' => $this->postedOnFeederPost($officer),
            'date_of_posting_on_promotion_from_post' => $this->postedOnFeederPost($officer),
        ], $officers);
    }

    /**
     * The Evaluation / Tabulation Chart (S18), with the per-year CR, integrity
     * and immovable-property returns.
     *
     * @param  list<array<string, mixed>>  $officers
     * @return list<array<string, mixed>>
     */
    public function evaluationRows(array $officers, string $currentPost, string $promotionFromPost): array
    {
        return array_map(function (array $officer) use ($currentPost, $promotionFromPost): array {
            /** @var list<int> $propertyMissing */
            $propertyMissing = $officer['property_missing'] ?? [];

            return [
                'sn' => $officer['sn'],
                'employee_number' => $this->employeeNumber($officer),
                'name' => $officer['name'],
                'dob' => $this->dateOfBirth($officer),
                'qualification' => $officer['qualification'] ?? 'Post-Graduate with B.Ed.',
                'promotion_to_post' => $currentPost,
                'promotion_from_post' => $promotionFromPost,
                'reservation_category_id' => $this->categoryId($officer['category']),
                'date_of_joining_service' => $this->joinedService($officer),
                'date_of_posting_on_promotion_to_post' => $this->postedOnFeederPost($officer),
                'date_of_posting_on_promotion_from_post' => $this->postedOnFeederPost($officer),
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
     * group from the feeder-post officers under consideration.
     *
     * **The chart lists chairs, not only people, and that is why `$sanctioned`
     * is a parameter.** Since the business directive of 2026-09-02 the vacancy
     * breakdown is *derived* from this list rather than stated beside it, and
     * {@see VacancyCalculationService} balances only
     * when the chart accounts for **every** sanctioned chair. A spec that names
     * seven officers against a sanctioned strength of twelve is therefore not an
     * under-filled post — it is five empty chairs that have to be written down.
     * They are padded here rather than in every spec, because the count is
     * arithmetic and nothing a demo author should have to keep in step by hand.
     *
     * `status` and `source` are stated explicitly for the same reason. Both
     * columns arrived on 2026-09-02, both are what the engine reads to decide
     * which quota a chair consumes, and {@see DpcProposalService::saveWorkingOfficers()}
     * defaults them to `working` / `null` when a caller omits them — which is a
     * chair held by nobody, so a whole seeded post held no quota at all and the
     * breakdown came out empty.
     *
     * @param  list<array<string, mixed>>  $officers
     * @param  int  $sanctioned  Sanctioned strength of the target post; the chart is padded with vacant chairs up to it
     * @param  int|null  $promotionPercentage  The Recruitment Rule's promotion share, which decides how many seated officers hold a promotion chair
     * @return list<array<string, mixed>>
     */
    public function workingOfficers(
        array $officers,
        string $currentPost,
        string $promotionFromPost,
        int $sanctioned,
        ?int $promotionPercentage = null,
    ): array {
        $promotionChairs = $promotionPercentage === null
            ? count($officers)
            : (int) floor($sanctioned * ($promotionPercentage / 100));

        $promotionSeated = 0;

        $rows = array_map(function (array $officer) use (
            $currentPost,
            $promotionFromPost,
            $promotionChairs,
            &$promotionSeated,
        ): array {
            /*
             | Which quota this officer's chair consumes.
             |
             | Promotion until the promotion chairs run out, then direct — which is
             | the same arithmetic `VacancyEngine::chairsByQuota()` applies, floor
             | rounding included, and it is duplicated here on purpose. A spec that
             | seated seven officers on a post with six promotion chairs was refused
             | for an overfilled quota, and fixing that by hand in each spec would
             | go stale the first time a Recruitment Rule percentage changed. A
             | spec may still name a source explicitly and override this.
             |
             | An officer away on deputation keeps the quota he arrived by. He is
             | excluded from the count on his deputation *dates* instead —
             | `DpcWorkingOfficer::substantivelyOccupies()` is the predicate the
             | arithmetic rests on, not this column.
             */
            $source = $officer['source'] ?? ($promotionSeated < $promotionChairs
                ? DpcWorkingOfficer::SOURCE_PROMOTION
                : DpcWorkingOfficer::SOURCE_DIRECT);

            if ($source === DpcWorkingOfficer::SOURCE_PROMOTION) {
                $promotionSeated++;
            }

            return [
                'sn' => $officer['sn'],
                'status' => DpcWorkingOfficer::STATUS_WORKING,

                'source' => $source,
                'employee_number' => $this->employeeNumber($officer),
                'name' => $officer['name'],
                'dob' => $this->dateOfBirth($officer),
                'qualification' => $officer['qualification'] ?? 'Post-Graduate with B.Ed.',
                'promotion_to_post' => $currentPost,
                'promotion_from_post' => $promotionFromPost,
                'date_of_joining_service' => $this->joinedService($officer),
                'date_of_posting_on_promotion_to_post' => $this->postedOnFeederPost($officer),
                'date_of_posting_on_promotion_from_post' => $this->postedOnFeederPost($officer),
                'reservation_category_id' => $this->categoryId($officer['category']),

                // The roster point the chair *is*, beside the category of the officer
                // sitting on it (business instruction, 2026-09-07). They differ in
                // real data, so the spec may say so; where it does not, the chair is
                // reserved as its occupant is.
                'seat_reservation_category_id' => $this->categoryId($officer['seat_category'] ?? $officer['category']),
                'roster_position' => $officer['roster'] ?? $officer['sn'],
                'date_of_retirement' => $officer['date_of_retirement'] ?? null,
                'deputation_from' => $officer['deputation_from'] ?? null,
                'deputation_to' => $officer['deputation_to'] ?? null,
            ];
        }, $officers);

        return [...$rows, ...$this->vacantChairs($rows, $currentPost, $promotionFromPost, $sanctioned)];
    }

    /**
     * The empty chairs that make the chart add up to the sanctioned strength.
     *
     * A vacant row carries **only** a category and a roster point. It has no
     * name, no employee number and no dates, because there is nobody on it —
     * `DpcRowRules` marks every one of those `prohibited_if:status,vacant`, and
     * the importer refuses a vacant row that carries them. Its `SOURCE` is left
     * null on purpose too: which quota an empty chair belongs to is arithmetic
     * across the post, never a cell on the row.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function vacantChairs(array $rows, string $currentPost, string $promotionFromPost, int $sanctioned): array
    {
        $held = count($rows);

        if ($held >= $sanctioned) {
            return [];
        }

        $lastSn = $held === 0 ? 0 : max(array_map(static fn (array $row): int => (int) $row['sn'], $rows));
        $chairs = [];

        foreach (range(1, $sanctioned - $held) as $offset) {
            $code = self::VACANT_CHAIR_CATEGORIES[($offset - 1) % count(self::VACANT_CHAIR_CATEGORIES)];

            $chairs[] = [
                'sn' => $lastSn + $offset,
                'status' => DpcWorkingOfficer::STATUS_VACANT,
                'source' => null,
                'promotion_to_post' => $currentPost,
                'promotion_from_post' => $promotionFromPost,
                'reservation_category_id' => $this->categoryId($code),
                'seat_reservation_category_id' => $this->categoryId($code),
                'roster_position' => $lastSn + $offset,
            ];
        }

        return $chairs;
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

        // Seniority on the promotion from post follows the serial number: officer 1 was
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
