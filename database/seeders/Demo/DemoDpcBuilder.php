<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Domain\Dpc\Enums\EvaluationDocumentKind;
use App\Domain\Dpc\Enums\PreviousDpcBasis;
use App\Domain\Dpc\Enums\VacancyPositionStatus;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcEvaluationDocument;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\Models\VacancyPositionSanctionedStrength;
use App\Domain\Dpc\Services\DpcCommitteeService;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\ProposalVerificationService;
use App\Domain\Dpc\Services\SanctionedStrengthSource;
use App\Domain\Dpc\Services\VacancyCalculationService;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DpcType;
use App\Domain\PreDpc\Models\PreDpcProposal;
use Illuminate\Support\Facades\Storage;
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
        private readonly DpcCommitteeService $committees,
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
            /*
             | Raised from the approved Pre-DPC that sanctioned this exercise
             | (change item 11). The demo seeds one Pre-DPC per ladder rung before
             | it builds any DPC — see `DemoMasterSeeder::approvedPreDpcs()` — so
             | the order this exercise runs under already exists.
             |
             | The post pair, the year, the class, the pay scale and the promotion
             | duration all come from it now, which is why the payload below is
             | down to the two facts the order does not carry.
             */
            $preDpc = $this->sanctioningPreDpc($department, $spec);

            $proposal = DemoClock::at($spec['drafted_at'], fn (): DpcProposal => $this->proposals->createFromPreDpc($preDpc, $ao, [
                'dpc_type_id' => DpcType::query()->where('code', $spec['dpc_type'] ?? 'fresh')->firstOrFail()->id,
                'legal_stay' => $spec['legal_stay'] ?? false,

                // Which of the order's feeders this DPC runs. Named and not inferred:
                // Lecturer is filled from both Teacher and Head Master, so the
                // order alone does not say, and the service refuses to guess.
                'promotion_from_post_id' => $this->departmentPost($department, (string) $spec['from'])->id,
            ]));

            DemoClock::at($spec['drafted_at'], function () use ($proposal, $ao, $spec, $preDpc): void {
                $this->committees->compose($proposal, $this->committee($spec['committee']), $ao);

                /*
                 | Sanctioned strength in the Vacancy Master (for the DPC year, since
                 | 2026-10-17 — it is no longer on the Department Post Mapping), then
                 | the officers, then the vacancies. Item 1 of the change register of
                 | 2026-08-27 took it out of the DPC's hands; item 2 made
                 | Working Strength read the uploaded Working Officer List, so the
                 | breakdown cannot be computed before that list exists. Saving the
                 | vacancies first — as this did — recorded an audit row describing
                 | the chart that was about to be replaced.
                 */
                $this->recordSanctionedStrength($proposal->refresh(), (int) $spec['sanctioned'], $ao);

                $this->proposals->saveWorkingOfficers(
                    $proposal,
                    $this->chart->workingOfficers(
                        $spec['working'],
                        $spec['to'],
                        $spec['from'],
                        (int) $spec['sanctioned'],

                        // The order's promotion share decides how many of the
                        // seated officers hold a promotion chair rather than a
                        // direct one. Read from the Pre-DPC because that is where
                        // the Recruitment Rule is projected from.
                        (int) $preDpc->promotion_recruitment_pct,
                    ),
                    $ao,
                );

                // The breakdown is derived from the chart just saved (business
                // directive, 2026-09-02), so the spec's `vacancies` no longer
                // travel: the chart decides them.
                $this->proposals->saveVacancies($proposal->refresh(), $ao);

                // The Seniority List is the source of truth for *who* is
                // evaluated (business directive, 2026-08-22): saving it projects
                // an evaluation row per officer, identity already filled in.
                $officers = $this->withinZone($proposal, $spec);

                $this->proposals->saveSeniorityEntries(
                    $proposal,
                    $this->chart->seniorityEntries($officers, $spec['from'], $spec['from']),
                    $ao,
                    // Rule 1.6 — the list is stated as on 1 April of the year
                    // before the DPC year.
                    $proposal->seniorityReferenceDate(),
                );

                // Evaluation data only, for exactly the officers the projection
                // produced — the same set, or the chart is refused.
                $this->proposals->saveEvaluationRows(
                    $proposal,
                    $this->evaluationOnly(
                        $this->chart->evaluationRows($officers, $spec['from'], $spec['from']),
                    ),
                    $ao,
                );

                // Coder Instructions points 2 and 3 — each officer's CR, Property
                // Return, PH questionnaire and documents.
                $this->completeEmployeeDetails($proposal->refresh(), $ao);

                // Rule 1.1, against the figures just saved — the step a
                // department completes before it may submit.
                $this->confirmRuleOneOne($proposal->refresh(), $ao);
            });

            return $proposal->refresh();
        });
    }

    /**
     * The year's sanctioned strength of the Promotion To Post, recorded in the
     * Vacancy Master where the application reads it, with a placeholder order PDF.
     */
    private function recordSanctionedStrength(DpcProposal $proposal, int $total, User $ao): void
    {
        $post = $proposal->promotionToPost;
        $year = app(SanctionedStrengthSource::class)->yearOf($proposal);
        $disk = (string) config('dpc.documents.disk', 'local');
        $path = 'private/vacancy-master/demo-sanction.pdf';
        $bytes = "%PDF-1.4\n% Demonstration sanctioning order\n%%EOF\n";
        Storage::disk($disk)->put($path, $bytes);

        $position = VacancyPosition::query()->firstOrCreate(
            ['department_id' => $proposal->department_id, 'post_name' => $post->name, 'vacancy_year' => $year, 'revision' => 1],
            [
                'department_post_id' => $post->id,
                'status' => VacancyPositionStatus::Draft->value,
                'period_from' => "{$year}-01-01",
                'period_to' => "{$year}-12-31",
                'created_by' => $ao->id,
            ],
        );

        $record = VacancyPositionSanctionedStrength::query()->create([
            'vacancy_position_id' => $position->id,
            'sanctioned_total' => $total,
            'order_number' => 'DEMO/SANCTION/'.$year,
            'order_date' => "{$year}-01-01",
            'disk' => $disk,
            'path' => $path,
            'original_name' => 'sanctioning-order.pdf',
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'recorded_by' => $ao->id,
            'recorded_at' => now(),
        ]);

        $position->forceFill(['sanctioned_strength_id' => $record->id])->save();
    }

    /**
     * The employee-wise details an Authorized Officer completes for each officer:
     * every CR year answered as one fully remarked report, the CR copies and the
     * channel undertaking, each filed IPR with its dates and copy, and "not PH".
     * The demonstration documents are one small placeholder PDF.
     */
    public function completeEmployeeDetails(DpcProposal $proposal, User $ao): void
    {
        $disk = (string) config('dpc.documents.disk', 'local');
        $path = 'private/employee-documents/demo-placeholder.pdf';
        $bytes = "%PDF-1.4\n% Demonstration placeholder document\n%%EOF\n";
        Storage::disk($disk)->put($path, $bytes);

        $document = function (DpcEvaluationRow $row, EvaluationDocumentKind $kind, ?int $year) use ($ao, $disk, $path, $bytes): void {
            DpcEvaluationDocument::query()->create([
                'dpc_proposal_id' => $row->dpc_proposal_id,
                'dpc_evaluation_row_id' => $row->id,
                'employee_number' => $row->employee_number,
                'kind' => $kind->value,
                'year' => $year,
                'undertaking_confirmed_at' => now(),
                'disk' => $disk,
                'path' => $path,
                'original_name' => $kind->value.($year === null ? '' : '-'.$year).'.pdf',
                'size_bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'uploaded_by' => $ao->id,
                'uploaded_at' => now(),
                // Rule 4.4: verified by the AO on upload and by the AH before approval.
                'ao_verified_at' => now(),
                'ah_verified_at' => now(),
            ]);
        };

        foreach ($proposal->evaluationRows()->with(['crGrades', 'propertyFlags'])->get() as $row) {
            foreach ($row->crGrades as $cr) {
                if ($cr->grade === null || $cr->put_up !== null) {
                    continue;
                }

                $cr->forceFill([
                    'put_up' => true,
                    'has_grading' => true,
                    'reports' => [[
                        'reporter_designation' => 'Reporting Officer', 'reporter_grade' => $cr->grade,
                        'reviewer_designation' => 'Reviewing Officer', 'reviewer_grade' => $cr->grade,
                        'accepting_designation' => 'Accepting Officer', 'accepting_grade' => $cr->grade,
                    ]],
                ])->save();

                $document($row, EvaluationDocumentKind::CrReport, $cr->year);
            }

            if ($row->crGrades->isNotEmpty() && $row->cr_channel_undertaking_at === null) {
                $row->forceFill(['cr_channel_undertaking_by' => $ao->id, 'cr_channel_undertaking_at' => now()])->save();
                $document($row, EvaluationDocumentKind::CrChannel, null);
            }

            foreach ($row->propertyFlags as $property) {
                // A year the chart left blank is a return that was not submitted —
                // the engine reads the two the same, and the officer answers it.
                if ($property->flag === null && $property->submitted_on === null) {
                    $property->forceFill(['flag' => false])->save();

                    continue;
                }

                if ($property->flag !== true || $property->submitted_on !== null) {
                    continue;
                }

                $property->forceFill([
                    'submitted_on' => sprintf('%d-01-15', $property->year + 1),
                    'next_increment_date' => sprintf('%d-07-01', $property->year + 1),
                ])->save();

                $document($row, EvaluationDocumentKind::PropertyReturn, $property->year);
            }

            $row->forceFill([
                'is_ph' => false,
                'ph_final_decision' => 'no_benefit',
                'ph_recorded_by' => $ao->id,
                'ph_recorded_at' => now(),
                // Rule 2.2: no departmental matter pending.
                'vigilance_pending' => false,
                // Rule 4.4: the service-record orders and the undertaking; rules 2.3 and
                // 4.4: not on unsanctioned deputation, no promotion refused.
                'dob_order_number' => 'SR/DOB/'.$row->sn,
                'dob_order_date' => $row->date_of_joining_service ?? now()->toDateString(),
                'joining_order_number' => 'SR/JOIN/'.$row->sn,
                'joining_order_date' => $row->date_of_joining_service ?? now()->toDateString(),
                'service_record_undertaking_by' => $ao->id,
                'service_record_undertaking_at' => now(),
                'unsanctioned_deputation' => false,
                'refused_promotion' => false,
                'declarations_recorded_by' => $ao->id,
                'declarations_recorded_at' => now(),
                'vigilance_recorded_by' => $ao->id,
                'vigilance_recorded_at' => now(),
            ])->save();
        }
    }

    /**
     * Rule 1.1 — the four confirmations the submission gate requires, made just
     * before submitting, against the figures the sections above produced. The
     * demonstration departments have no earlier DPC for these posts, which the
     * remarks state.
     */
    private function confirmRuleOneOne(DpcProposal $proposal, User $ao): void
    {
        $verification = app(ProposalVerificationService::class);

        foreach ($verification->status($proposal) as $entry) {
            if ($entry['complete']) {
                continue;
            }

            $verification->confirm(
                $proposal,
                $entry['item'],
                $ao,
                'No earlier DPC has been held for this post.',
                ['basis' => PreviousDpcBasis::None->value],
                null,
                // Rule 4.4: the latest Recruitment Rule's notification date.
                '2026-01-01',
            );
        }
    }

    /**
     * S19 — the Authorized Officer submits the completed proposal for the
     * Administrative Head's review.
     */
    public function submit(DpcProposal $proposal, User $ao, string $at): DpcProposal
    {
        DemoActor::as($ao, fn () => DemoClock::at(
            $at,
            function () use ($proposal, $ao): void {
                $this->confirmRuleOneOne($proposal->refresh(), $ao);
                $this->proposals->submit($proposal, $ao, DemoActor::request());
            },
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
        return array_map(static function (array $member): array {
            $row = [
                'user_id' => $member['user']?->id,
                'name' => $member['name'] ?? $member['user']?->name,
                'designation' => $member['designation'] ?? null,
                'mobile' => $member['mobile'] ?? null,
                'email' => $member['email'] ?? null,
                'capacity' => ($member['capacity'] ?? CommitteeCapacity::Member)->value,
            ];

            // The six composition attributes (item 8), defaulting to false so a
            // spec that names none is still a well-formed row.
            foreach (DpcCommitteeMember::ATTRIBUTE_COLUMNS as $column) {
                $row[$column] = (bool) ($member[$column] ?? false);
            }

            return $row;
        }, $members);
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
     * **The evaluation chart is trimmed with it**, necessarily: since the
     * directive of 2026-08-22 the chart carries exactly the Seniority List's
     * officers, so the two cannot differ. When the list was trimmed and the chart
     * was not, the seeder refused itself — which is the invariant working.
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
     * Strip identity out of a chart payload, leaving what evaluation is about.
     *
     * Public because the journey seeder's Review correction needs the same
     * treatment, and two copies of this would be two chances to forget a field.
     *
     * The chart no longer accepts employee identity — it is projected from the
     * Seniority List — so passing it would offer values that are going to be
     * ignored. `DemoChart` still produces the full officer shape because the
     * Seniority List needs it; this is where the two parts separate.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function evaluationOnly(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'sn' => $row['sn'],
            'cr_grades' => $row['cr_grades'] ?? [],
            'loyalty_flags' => $row['loyalty_flags'] ?? [],
            'property_flags' => $row['property_flags'] ?? [],
            'tribal_area_service_years' => $row['tribal_area_service_years'] ?? null,
            'note' => $row['note'] ?? null,
        ], $rows);
    }

    /**
     * The approved Pre-DPC this demo DPC is raised under.
     *
     * Looked up rather than created: `DemoMasterSeeder` has already run one
     * Pre-DPC per Promotion To Post through the real workflow, and creating a second
     * here would breach `pre_dpc_active_exercise_unique_v2` — which is item 9
     * working exactly as intended.
     *
     * The feeder is matched with a `whereHas` and not a column comparison, because
     * one order names every post its promotion may draw on.
     *
     * @param  array<string, mixed>  $spec
     */
    private function sanctioningPreDpc(Department $department, array $spec): PreDpcProposal
    {
        $from = $this->departmentPost($department, (string) $spec['from']);

        return PreDpcProposal::query()
            ->activeApproved()
            ->where('department_id', $department->id)
            ->whereHas('promotionSources', fn ($query) => $query->where('promotion_from_post_id', $from->id))
            ->where('promotion_to_post_id', $this->departmentPost($department, (string) $spec['to'])->id)
            ->firstOrFail();
    }

    private function departmentPost(Department $department, string $name): DepartmentPost
    {
        return DepartmentPost::query()
            ->where('department_id', $department->id)
            ->where('name', $name)
            ->firstOrFail();
    }
}
