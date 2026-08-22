<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\VacancyCalculationService;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;
use RuntimeException;

/**
 * Builds a DPC Proposal (PPT S12–S25) from a compact specification by calling
 * the same service the Authorized Officer's screens call, section by section,
 * in the order the deck prescribes: post selection, committee, working officers
 * and vacancies, seniority list, evaluation chart.
 *
 * Every proposal in the demo database is built through here, whatever state it
 * is left in. A Draft, a Submitted and a fully concluded DPC therefore differ
 * only in how far along the workflow they were taken — which is what makes the
 * pipeline screens honest rather than a set of unrelated fixtures.
 */
final class DemoDpcBuilder
{
    public function __construct(
        private readonly DpcProposalService $proposals,
        private readonly DemoChart $chart,
    ) {}

    /**
     * A complete Draft proposal: every section of S14–S18 filled, ready to
     * submit. Written under the Authorized Officer's identity, at the dates
     * given in the specification.
     *
     * @param  array<string, mixed>  $spec
     */
    public function draft(Department $department, User $ao, array $spec): DpcProposal
    {
        return DemoActor::as($ao, function () use ($department, $ao, $spec): DpcProposal {
            $proposal = DemoClock::at($spec['drafted_at'], fn (): DpcProposal => $this->proposals->createDraft($department, $ao, [
                'dpc_type_id' => DpcType::query()->where('code', $spec['dpc_type'] ?? 'fresh')->firstOrFail()->id,
                'post_to_cadre_id' => $this->cadre($department, $spec['to'])->id,
                'post_from_cadre_id' => $this->cadre($department, $spec['from'])->id,
                'legal_stay' => $spec['legal_stay'] ?? false,
                'proposal_date' => $spec['proposal_date'],
            ]));

            DemoClock::at($spec['drafted_at'], function () use ($proposal, $ao, $spec): void {
                $this->proposals->saveCommittee($proposal, $this->committee($spec['committee']), $ao);

                $this->proposals->saveVacancies(
                    $proposal,
                    (int) $spec['sanctioned'],
                    $this->vacancies($spec['vacancies']),
                );

                $this->proposals->saveWorkingOfficers(
                    $proposal,
                    $this->chart->workingOfficers($spec['working'], $spec['to'], $spec['from']),
                    $ao,
                );

                $this->proposals->saveSeniorityEntries(
                    $proposal,
                    $this->chart->seniorityEntries(
                        $this->withinZone($proposal, $spec),
                        $spec['from'],
                        $spec['from'],
                    ),
                    $ao,
                );

                $this->proposals->saveEvaluationRows(
                    $proposal,
                    $this->chart->evaluationRows($spec['officers'], $spec['from'], $spec['from']),
                    $ao,
                );
            });

            return $proposal->refresh();
        });
    }

    /**
     * S19 — the Authorized Officer submits the completed proposal for the
     * Administrative Head's review.
     */
    public function submit(DpcProposal $proposal, User $ao, string $at): DpcProposal
    {
        DemoActor::as($ao, fn () => DemoClock::at(
            $at,
            fn () => $this->proposals->submit($proposal, $ao, DemoActor::request()),
        ));

        return $proposal->refresh();
    }

    /**
     * S21–S25 — the Administrative Head reviews every section and signs once.
     * This is the transition that mints the DPC ID and forwards the proposal to
     * the Commission.
     */
    public function approve(DpcProposal $proposal, User $ah, string $at): DpcProposal
    {
        DemoActor::as($ah, fn () => DemoClock::at(
            $at,
            fn () => $this->proposals->approve($proposal, $ah, DemoActor::request()),
        ));

        return $proposal->refresh();
    }

    /**
     * Draft → submitted → approved in one call, for the proposals whose value to
     * the demo is the state they end in rather than the steps along the way.
     *
     * @param  array<string, mixed>  $spec
     */
    public function approved(Department $department, User $ao, User $ah, array $spec): DpcProposal
    {
        $proposal = $this->draft($department, $ao, $spec);
        $this->submit($proposal, $ao, $spec['submitted_at']);

        return $this->approve($proposal, $ah, $spec['approved_at']);
    }

    /**
     * The DPC Committee (S15). A member who holds a portal account is linked to
     * it by `user_id` — the service then takes their name and designation from
     * their own record, so the committee cannot claim a title the account does
     * not carry.
     *
     * @param  list<array<string, mixed>>  $members
     * @return list<array<string, mixed>>
     */
    private function committee(array $members): array
    {
        return array_map(static fn (array $member): array => [
            'user_id' => $member['user']?->id,
            'name' => $member['name'] ?? $member['user']?->name,
            'designation' => $member['designation'] ?? null,
            'mobile' => $member['mobile'] ?? null,
            'email' => $member['email'] ?? null,
            'capacity' => ($member['capacity'] ?? CommitteeCapacity::Member)->value,
        ], $members);
    }

    /**
     * The officers the Seniority List may carry: exactly the Zone of
     * Consideration, where the class pair has one (business directive,
     * 2026-08-22).
     *
     * **Trimming here changes no demonstrated outcome, and that is why it is the
     * right fix.** The scoring engine has always considered `min(zone,
     * available)` officers, so a list of 16 against a zone of 14 already put two
     * officers outside contention — they were carried, ranked and then ignored.
     * Cutting the list to the zone makes the data say what the engine was
     * already doing, and every promotion list the demo produces is unchanged.
     *
     * The **evaluation chart is deliberately not trimmed.** No rule ties its
     * length to the zone, and the demo narrative points at specific chart rows —
     * including the officer held for a CR shortfall — which a silent truncation
     * could remove.
     *
     * A spec whose vacancy breakdown does not balance is a **fault in the demo
     * data**, so it throws rather than being quietly corrected: sanctioned counts
     * are chosen to tell a story, and adjusting one behind the author's back
     * would make the story wrong in a way nobody would notice.
     *
     * @param  array<string, mixed>  $spec
     * @return list<array<string, mixed>>
     */
    private function withinZone(DpcProposal $proposal, array $spec): array
    {
        /** @var list<array<string, mixed>> $officers */
        $officers = $spec['officers'];

        $vacancies = app(VacancyCalculationService::class);
        $summary = $vacancies->summary($proposal->fresh());

        if (! $summary['balanced']) {
            throw new RuntimeException(sprintf(
                'Demo spec for %s → %s does not balance: sanctioned %s − %d working = %s, '
                .'but the vacancy breakdown totals %d. Fix the spec, not this builder.',
                (string) $spec['from'],
                (string) $spec['to'],
                var_export($summary['sanctioned'], true),
                $summary['working'],
                var_export($summary['total_vacancy'], true),
                $summary['classified_total'],
            ));
        }

        $required = $summary['required_seniority'];

        if ($required === null) {
            return $officers; // No zone formula for this class pair, so no cap.
        }

        if (count($officers) < $required) {
            throw new RuntimeException(sprintf(
                'Demo spec for %s → %s offers %d officer(s) but the Zone of Consideration is %d. '
                .'Add officers to the spec — the list cannot be padded with plausible-looking strangers.',
                (string) $spec['from'],
                (string) $spec['to'],
                count($officers),
                $required,
            ));
        }

        return array_slice($officers, 0, $required);
    }

    /**
     * The S16 vacancy breakdown: one row per (vacancy type, reservation
     * category) pair, never a column per type.
     *
     * @param  list<array{0:string, 1:string, 2:int}>  $vacancies
     * @return list<array<string, mixed>>
     */
    private function vacancies(array $vacancies): array
    {
        $types = VacancyType::query()->pluck('id', 'code');
        $categories = ReservationCategory::query()->pluck('id', 'code');

        return array_map(static fn (array $vacancy): array => [
            'vacancy_type_id' => $types[$vacancy[0]],
            'reservation_category_id' => $categories[$vacancy[1]],
            'count' => $vacancy[2],
        ], $vacancies);
    }

    private function cadre(Department $department, string $name): Cadre
    {
        return Cadre::query()
            ->where('department_id', $department->id)
            ->where('name', $name)
            ->firstOrFail();
    }
}
