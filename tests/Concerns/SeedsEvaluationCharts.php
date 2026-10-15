<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\EvaluationCandidateService;

/**
 * Builds an Evaluation Chart the way the application now builds one — Seniority
 * List first, chart projected from it (business directive, 2026-08-22).
 *
 * **Why the fixtures needed a shared helper at all.** Before the directive, a
 * test could hand `saveEvaluationRows()` a list of officers with their identity
 * and be done. That is exactly the duplication the directive removes, so the
 * fixtures have to model the two steps: the officers arrive on the Seniority
 * List, the projection turns them into chart rows, and only then is evaluation
 * data written. Doing that inline in every test file would have put the same
 * eight lines in five places — and the point of the directive is that officer
 * identity lives in one place.
 *
 * The officer arrays these methods accept are the *old* shape: identity and
 * evaluation data together. That is deliberate, so the existing tests keep
 * reading as statements about scoring rather than about plumbing. This trait is
 * what splits them.
 */
trait SeedsEvaluationCharts
{
    /**
     * Seed the Seniority List and the Evaluation Chart from one list of officers.
     *
     * @param  list<array<string, mixed>>  $officers  identity and evaluation data together
     */
    protected function seedSeniorityAndChart(DpcProposal $proposal, array $officers): void
    {
        $service = app(DpcProposalService::class);

        // Step 1 — the officers become the Seniority List, which projects a chart
        // row per officer with identity already filled in.
        $service->saveSeniorityEntries($proposal, array_map(
            static fn (array $officer): array => [
                'seniority_number' => $officer['sn'],
                'employee_number' => $officer['employee_number'] ?? null,
                'name' => $officer['name'],
                'dob' => $officer['dob'],
                'qualification' => $officer['qualification'] ?? null,
                'promotion_to_post' => $officer['promotion_to_post'] ?? null,
                'promotion_from_post' => $officer['promotion_from_post'] ?? null,
                'reservation_category_id' => $officer['reservation_category_id'],
                'date_of_joining_service' => $officer['date_of_joining_service'] ?? null,
                'date_of_posting_on_promotion_to_post' => $officer['date_of_posting_on_promotion_to_post'] ?? null,
                'date_of_posting_on_promotion_from_post' => $officer['date_of_posting_on_promotion_from_post'] ?? null,
            ],
            $officers,
        ));

        // Step 2 — only the evaluation-specific fields. Identity in `$officers` is
        // ignored from here on, which is the behaviour under test.
        $service->saveEvaluationRows($proposal->fresh() ?? $proposal, array_map(
            static fn (array $officer): array => [
                'sn' => $officer['sn'],
                'cr_grades' => $officer['cr_grades'] ?? [],
                'loyalty_flags' => $officer['loyalty_flags'] ?? [],
                'property_flags' => $officer['property_flags'] ?? [],
                'tribal_area_service_years' => $officer['tribal_area_service_years'] ?? null,
                'note' => $officer['note'] ?? null,
            ],
            $officers,
        ));

        $proposal->refresh();
    }

    /**
     * Add one officer to a proposal that may already have others.
     *
     * **Writes the two tables directly rather than through
     * `DpcProposalService`**, because the fixtures that use this build proposals
     * that are *already approved* — a proceedings test starts from a concluded
     * DPC — and the service rightly refuses to edit a section of one. The
     * projection is still the real one, so the chart row is produced exactly as
     * production produces it; only the editability guard is stepped around, and
     * only because the fixture is constructing history rather than performing a
     * workflow.
     *
     * Calling this five times builds five officers: each call adds a seniority
     * entry and re-projects, and the projection carries existing evaluation data
     * across for officers still on the list.
     *
     * @param  array<int, string>  $grades  year => CR grade
     * @param  list<int>  $propertyMissingYears  years whose property flag stays null
     */
    protected function seedOneOfficer(
        DpcProposal $proposal,
        int $sn,
        string $name,
        int $categoryId,
        array $grades,
        array $propertyMissingYears = [],
        ?string $dob = null,
        ?string $qualification = null,
    ): DpcEvaluationRow {
        $proposal->seniorityEntries()->create([
            'seniority_number' => $sn,
            'name' => $name,
            'dob' => $dob ?? '1967-04-09',
            'qualification' => $qualification ?? 'BA',
            'reservation_category_id' => $categoryId,
        ]);

        app(EvaluationCandidateService::class)->project($proposal->fresh() ?? $proposal);

        /** @var DpcEvaluationRow $row */
        $row = $proposal->fresh()->evaluationRows()->where('sn', $sn)->firstOrFail();

        foreach ($grades as $year => $grade) {
            $row->crGrades()->create(['year' => $year, 'grade' => $grade]);
            $row->loyaltyFlags()->create(['year' => $year, 'flag' => true]);
            $row->propertyFlags()->create([
                'year' => $year,
                'flag' => in_array($year, $propertyMissingYears, true) ? null : true,
            ]);
        }

        return $row;
    }
}
