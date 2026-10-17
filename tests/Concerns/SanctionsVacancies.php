<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\DpcWorkingOfficer;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\Master\Models\ReservationCategory;
use App\Enums\MasterStatus;

/**
 * Record a sanctioned strength and produce a vacancy, in one call.
 *
 * **Why a fixture needs this at all.** The change register of 2026-08-27 took the
 * sanctioned strength out of the officer's hands (item 1) and the promotion
 * percentage out of the form (item 6): the first lives on the Department Post
 * Mapping and the second on the Recruitment Rule. So a test that wants a vacancy
 * can no longer state one — it has to arrange the two masters that produce it.
 *
 * That is three lines of setup at every call site, which is three places to get it
 * subtly different. Here instead, so a test that is about *scoring* or *importing*
 * can ask for "a proposal with 10 sanctioned posts" and get on with its subject.
 *
 * Writing the columns directly is deliberate: the application supersedes a mapping
 * rather than editing it, but a fixture is not the application, and driving a
 * Government Order through the master screens would make every one of these tests
 * depend on the Administrator's role.
 */
trait SanctionsVacancies
{
    use RecordsSanctionedStrength;

    /**
     * Sanction `$sanctioned` posts on the Promotion To Post, then leave enough of
     * them vacant to produce the vacancy the caller asked for.
     *
     * **Vacancy is stated on the chart since the business directive of
     * 2026-09-02.** It used to be a breakdown saved beside the chart, so this
     * helper used to take those rows; now a vacancy *is* a chart row whose status
     * is `vacant`, and the breakdown is derived from them. `$rows` is still
     * accepted in its old shape so call sites do not all have to change — each
     * row's `count` becomes that many vacant posts in that row's reservation
     * category, which is exactly what the row used to assert.
     *
     * With no `$rows`, every sanctioned post is left vacant: a test asking for "10
     * sanctioned posts" and nothing else wants 10 vacancies to score against.
     *
     * `$promotionPercentage` defaults to 100 so that Total Vacancy and Promotion
     * Vacancy coincide — which is what a test about something else wants, because it
     * makes the promotion seat count equal to the number it already reasons about.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function sanctionVacancies(
        DpcProposal $proposal,
        int $sanctioned,
        array $rows = [],
        float $promotionPercentage = 100.0,
    ): DpcProposal {
        $post = $proposal->promotionToPost;

        if ($post !== null) {
            $this->recordSanctionedStrength($proposal, $sanctioned);

            // Only if the post has no rule yet: a test that registered its own
            // percentage must keep it.
            $hasRule = RecruitmentRule::query()
                ->where('post_id', $post->id)
                ->where('status', MasterStatus::Active->value)
                ->exists();

            if (! $hasRule) {
                RecruitmentRule::query()->create([
                    'post_id' => $post->id,
                    'version' => 1,
                    'government_order_number' => 'GO/FIXTURE/1',
                    'direct_recruitment_pct' => 100 - $promotionPercentage,
                    'promotion_recruitment_pct' => $promotionPercentage,
                    'effective_from' => '2020-01-01',
                    'status' => MasterStatus::Active->value,
                ]);
            }
        }

        $service = app(DpcProposalService::class);

        $service->saveWorkingOfficers($proposal->refresh(), $this->vacantChart($sanctioned, $rows));

        return $service->saveVacancies($proposal->refresh());
    }

    /**
     * A chart of vacant posts, one per vacancy the caller asked for.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function vacantChart(int $sanctioned, array $rows): array
    {
        $category = ReservationCategory::query()->orderBy('id')->firstOrFail()->id;

        $wanted = [];

        foreach ($rows as $row) {
            $count = (int) ($row['count'] ?? 0);

            for ($i = 0; $i < $count; $i++) {
                $wanted[] = (int) ($row['reservation_category_id'] ?? $category);
            }
        }

        if ($rows === []) {
            $wanted = array_fill(0, max(0, $sanctioned), $category);
        }

        // The chart may not claim more chairs than the post is sanctioned for.
        $wanted = array_slice($wanted, 0, max(0, $sanctioned));

        $chart = [];

        foreach ($wanted as $index => $categoryId) {
            $chart[] = [
                'sn' => $index + 1,
                'status' => DpcWorkingOfficer::STATUS_VACANT,
                'reservation_category_id' => $categoryId,
            ];
        }

        /*
         | The chart must account for **every** sanctioned post since the business
         | directive of 2026-09-02, so the chairs the caller did not ask to be
         | vacant are filled by officers who came by promotion — the only mode a
         | 100%-promotion rule allows, which is what this helper registers.
         */
        for ($sn = count($chart) + 1; $sn <= $sanctioned; $sn++) {
            $chart[] = [
                'sn' => $sn,
                'status' => DpcWorkingOfficer::STATUS_WORKING,
                'source' => DpcWorkingOfficer::SOURCE_PROMOTION,
                'name' => 'Incumbent '.$sn,
                'dob' => '1975-01-01',
                'reservation_category_id' => $category,
            ];
        }

        return $chart;
    }
}
