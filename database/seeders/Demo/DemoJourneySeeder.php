<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Cgpsc\Services\SchedulingService;
use App\Domain\Circulation\Enums\CirculationDecision;
use App\Domain\Circulation\Models\DpcCirculation;
use App\Domain\Circulation\Services\CirculationCommissionService;
use App\Domain\Circulation\Services\CirculationDocumentService;
use App\Domain\Circulation\Services\CirculationService;
use App\Domain\Circulation\Services\CirculationWorkflowService;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Master\Models\ReviewReason;
use App\Domain\Proceeding\Enums\CorrectableField;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Services\ProceedingCalculationService;
use App\Domain\Proceeding\Services\ProceedingCorrectionService;
use App\Domain\Proceeding\Services\ProceedingDocumentService;
use App\Domain\Proceeding\Services\ProceedingService;
use App\Domain\Proceeding\Services\ResultApprovalService;
use App\Domain\Review\Services\ReviewDpcService;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The School Education Department demo journey: two DPCs taken all the way
 * through, one ending in a Review and the other in a Circulation.
 *
 * Everything here is produced by calling the application's own services in the
 * order a real case takes, under the identity of the officer who would have
 * done it, at the date it would have happened. Nothing is inserted directly and
 * no business rule is bypassed — which is the only way a demonstration can
 * claim to show the system rather than a picture of it.
 *
 * **Why two DPCs and not one.** A Review supersedes the DPC it corrects, and a
 * superseded DPC may no longer be circulated (the operative list has moved on).
 * Putting both endings on a single DPC would leave the Circulation screens
 * demonstrating a state the application refuses to create. So the department
 * runs two promotions: the Joint Director DPC, which a Review later corrects,
 * and the Principal DPC, on which one officer is held and a Circulation
 * releases the promotion. Both are the same department, the same officers'
 * ladder, and the same case file.
 */
class DemoJourneySeeder extends Seeder
{
    /** The DPC that ends in a Review DPC. */
    public const JOINT_DIRECTOR_DPC = 'Joint Director, Education';

    /** The DPC that ends in a Circulation DPC. */
    public const PRINCIPAL_DPC = 'Principal, Higher Secondary School';

    public function __construct(
        private readonly DemoDpcBuilder $builder,
        private readonly DpcProposalService $proposals,
        private readonly AllotmentService $allotments,
        private readonly SchedulingService $scheduling,
        private readonly ProceedingService $proceedings,
        private readonly ProceedingCalculationService $calculations,
        private readonly ProceedingCorrectionService $corrections,
        private readonly ResultApprovalService $results,
        private readonly ProceedingDocumentService $documents,
        private readonly ReviewDpcService $reviews,
        private readonly CirculationService $circulations,
        private readonly CirculationWorkflowService $circulationWorkflow,
        private readonly CirculationCommissionService $commission,
        private readonly CirculationDocumentService $circulationDocuments,
        private readonly DemoChart $chart,
    ) {}

    public function run(): void
    {
        $department = Department::query()->where('code', 'SE')->firstOrFail();

        $ao = $this->officer($department, Role::AuthorizedOfficer);
        $ah = $this->officer($department, Role::AdministrativeHead);
        $chairman = User::query()->where('email', 'chairman@cgpsc.gov.in')->firstOrFail();
        $memberOne = User::query()->where('email', 'member1@cgpsc.gov.in')->firstOrFail();
        $memberTwo = User::query()->where('email', 'member2@cgpsc.gov.in')->firstOrFail();

        $jointDirector = $this->jointDirectorDpc($department, $ao, $ah, $chairman, $memberOne);
        $this->reviewOf($jointDirector, $ao, $ah, $chairman, $memberOne);

        $principal = $this->principalDpc($department, $ao, $ah, $chairman, $memberTwo);
        $this->circulationFor($principal, $ao, $ah, $memberTwo);
    }

    // ------------------------------------------------------------------
    // The Joint Director DPC — the complete Fresh DPC, S12 through S34.
    // ------------------------------------------------------------------

    private function jointDirectorDpc(
        Department $department,
        User $ao,
        User $ah,
        User $chairman,
        User $member,
    ): DpcProposal {
        $officers = DemoOfficers::districtEducationOfficers();

        $proposal = $this->builder->approved($department, $ao, $ah, [
            'from' => 'District Education Officer',
            'to' => self::JOINT_DIRECTOR_DPC,
            'proposal_date' => '2026-04-10',
            'drafted_at' => '2026-04-10 10:15',
            'submitted_at' => '2026-04-20 16:40',
            'approved_at' => '2026-04-24 12:05',
            'sanctioned' => 12,
            'vacancies' => [
                ['clear', 'UR', 1],
                ['from_retirement', 'UR', 1],
                ['clear', 'SC', 1],
                ['clear', 'ST', 1],
                ['from_promotion', 'OBC', 1],
            ],
            'committee' => $this->committee($ah, $ao),
            'working' => DemoOfficers::jointDirectorsInPost(),
            'officers' => $officers,
        ]);

        // S28 — the Chairman allots the forwarded DPC to a Member.
        DemoActor::as($chairman, fn () => DemoClock::at('2026-04-28 11:00', fn () => $this->allotments->allot(
            $proposal,
            $member,
            $chairman,
            DemoActor::request(),
            Carbon::parse('2026-05-20'),
        )));

        // S30 — the Member accepts, then fixes the date and time of the sitting.
        DemoActor::as($member, function () use ($proposal, $member): void {
            DemoClock::at('2026-04-30 09:50', fn () => $this->scheduling->accept($proposal, $member, DemoActor::request()));
            DemoClock::at('2026-05-04 15:10', fn () => $this->scheduling->schedule(
                $proposal,
                $member,
                DemoActor::request(),
                Carbon::parse('2026-05-20'),
                '11:00',
            ));
        });

        $proceeding = $this->conductSitting($proposal, $member, [
            'opened_at' => '2026-05-20 11:05',
            'calculated_at' => '2026-05-20 11:25',
            'approved_at' => '2026-05-20 12:30',
            'documents_at' => '2026-05-20 12:45',
            // S33 EDIT — the department's 2024 CR entry for serial 7 was
            // disputed; the Commission accepts the corrected grade at the
            // sitting and recalculates. The officer crosses the qualifying
            // score as a result, which is the whole reason the screen exists.
            'correction' => [
                'at' => '2026-05-20 11:45',
                'recalculated_at' => '2026-05-20 11:50',
                'field' => CorrectableField::CrGrade,
                'sn' => 7,
                'year' => 2024,
                'value' => 'क+',
                'reason' => 'Departmental record produced at the sitting shows the 2024 CR grade as क+; '
                    .'the chart entry of ख was a transcription error.',
            ],
        ]);

        $this->publish($proceeding, $member, '2026-05-20 12:50');

        return $proposal->refresh();
    }

    // ------------------------------------------------------------------
    // The Review DPC — S35/S37, linked to the DPC above.
    // ------------------------------------------------------------------

    /**
     * A Review DPC raised against the concluded Joint Director DPC, corrected,
     * approved by the department, and then **conducted by the Commission**.
     *
     * It is taken all the way because supersession is not a consequence of
     * raising a Review — it is a consequence of the Commission approving its
     * result (AQ-9). A Review left at the department's approval would leave the
     * original still operative, and the supersession banner, the superseded
     * watermark on the original's documents and the correction chain would all
     * have nothing to show.
     */
    private function reviewOf(DpcProposal $original, User $ao, User $ah, User $chairman, User $member): DpcProposal
    {
        $reason = ReviewReason::query()->where('code', 'wrong_cr')->firstOrFail();

        $review = DemoActor::as($ao, fn (): DpcProposal => DemoClock::at('2026-06-10 10:30', fn (): DpcProposal => $this->reviews->create(
            $original,
            $ao,
            Carbon::parse('2026-06-10'),
            [[
                'review_reason_id' => $reason->id,
                'detail' => 'The 2024 CR grade of Smt. Geeta Sharma (S.N. 8) was recorded as ग. The '
                    .'departmental record produced with the representation shows क+. The officer was '
                    .'excluded from the Joint Director DPC on the strength of the wrong entry.',
            ]],
        )));

        // S38 — Correction in Tabulation Chart. The Review starts as a copy of
        // the **department's** submission, so it carries two corrections:
        //
        //  - S.N. 8, the entry the Review was raised over; and
        //  - S.N. 7, which the Commission corrected at the original sitting.
        //
        // The second one matters. A sitting correction belongs to that sitting,
        // not to the department's proposal, so it is not inherited — and a
        // Review that silently dropped it would take an officer back off the
        // list the Commission had already put him on.
        $corrected = $this->withCorrectedGrades(DemoOfficers::districtEducationOfficers(), [
            ['sn' => 8, 'year' => 2024, 'grade' => 'क+'],
            ['sn' => 7, 'year' => 2024, 'grade' => 'क+'],
        ]);

        DemoActor::as($ao, fn () => DemoClock::at('2026-06-12 14:20', fn () => $this->proposals->saveEvaluationRows(
            $review,
            $this->chart->evaluationRows($corrected, 'District Education Officer', 'District Education Officer'),
            $ao,
        )));

        $this->builder->submit($review, $ao, '2026-06-15 11:05');
        $this->builder->approve($review, $ah, '2026-06-18 10:40');

        // The same Member who conducted the original conducts the correction.
        DemoActor::as($chairman, fn () => DemoClock::at('2026-06-22 11:20', fn () => $this->allotments->allot(
            $review,
            $member,
            $chairman,
            DemoActor::request(),
            Carbon::parse('2026-07-14'),
        )));

        DemoActor::as($member, function () use ($review, $member): void {
            DemoClock::at('2026-06-24 10:15', fn () => $this->scheduling->accept($review, $member, DemoActor::request()));
            DemoClock::at('2026-06-26 14:40', fn () => $this->scheduling->schedule(
                $review,
                $member,
                DemoActor::request(),
                Carbon::parse('2026-07-14'),
                '11:00',
            ));
        });

        $proceeding = $this->conductSitting($review, $member, [
            'opened_at' => '2026-07-14 11:03',
            'calculated_at' => '2026-07-14 11:20',
            'approved_at' => '2026-07-14 12:05',
            'documents_at' => '2026-07-14 12:20',
        ]);

        $this->publish($proceeding, $member, '2026-07-14 12:25');

        return $review->refresh();
    }

    // ------------------------------------------------------------------
    // The Principal DPC — concluded with one officer held.
    // ------------------------------------------------------------------

    private function principalDpc(
        Department $department,
        User $ao,
        User $ah,
        User $chairman,
        User $member,
    ): DpcProposal {
        $proposal = $this->builder->approved($department, $ao, $ah, [
            'from' => 'Lecturer',
            'to' => self::PRINCIPAL_DPC,
            'proposal_date' => '2026-03-05',
            'drafted_at' => '2026-03-05 11:20',
            'submitted_at' => '2026-03-14 17:05',
            'approved_at' => '2026-03-18 10:15',
            'sanctioned' => 10,
            'vacancies' => [
                ['clear', 'UR', 2],
                ['clear', 'OBC', 1],
            ],
            'committee' => $this->committee($ah, $ao),
            'working' => DemoOfficers::principalsInPost(),
            'officers' => DemoOfficers::lecturers(),
        ]);

        DemoActor::as($chairman, fn () => DemoClock::at('2026-03-20 12:30', fn () => $this->allotments->allot(
            $proposal,
            $member,
            $chairman,
            DemoActor::request(),
            Carbon::parse('2026-04-08'),
        )));

        DemoActor::as($member, function () use ($proposal, $member): void {
            DemoClock::at('2026-03-22 10:05', fn () => $this->scheduling->accept($proposal, $member, DemoActor::request()));
            DemoClock::at('2026-03-25 16:00', fn () => $this->scheduling->schedule(
                $proposal,
                $member,
                DemoActor::request(),
                Carbon::parse('2026-04-08'),
                '11:00',
            ));
        });

        $proceeding = $this->conductSitting($proposal, $member, [
            'opened_at' => '2026-04-08 11:02',
            'calculated_at' => '2026-04-08 11:30',
            'approved_at' => '2026-04-08 12:15',
            'documents_at' => '2026-04-08 12:35',
        ]);

        $this->publish($proceeding, $member, '2026-04-08 12:40');

        return $proposal->refresh();
    }

    // ------------------------------------------------------------------
    // The Circulation DPC — S45/S47/S48, linked to the held officer above.
    // ------------------------------------------------------------------

    private function circulationFor(DpcProposal $origin, User $ao, User $ah, User $member): DpcCirculation
    {
        $holds = $this->circulations->outstandingHolds($origin);

        if ($holds->isEmpty()) {
            throw new \RuntimeException(
                'The Principal DPC produced no held officer, so the Circulation demo has nothing to resolve. '
                .'Check the immovable-property returns seeded in DemoOfficers::lecturers().'
            );
        }

        $circulation = DemoActor::as($ao, fn (): DpcCirculation => DemoClock::at('2026-07-01 10:15', fn (): DpcCirculation => $this->circulations->raise(
            $origin,
            $ao,
            Carbon::parse('2026-07-01'),
            $holds->map(static fn ($hold): array => [
                'hold_uuid' => $hold->uuid,
                'resolution_detail' => 'The immovable-property return for 2022 has since been received from the '
                    .'officer and taken on record by the department.',
                'resolution_reference' => 'SE/DPC/CIR/2026/0114 dated 26-06-2026',
                'resolution_received_on' => '2026-06-26',
            ])->all(),
        )));

        DemoActor::as($ao, fn () => DemoClock::at('2026-07-02 11:40', fn () => $this->circulationWorkflow->submit(
            $circulation,
            $ao,
            DemoActor::request(),
        )));

        DemoActor::as($ah, fn () => DemoClock::at('2026-07-05 15:20', fn () => $this->circulationWorkflow->approve(
            $circulation->refresh(),
            $ah,
            DemoActor::request(),
        )));

        // S48 — the Member who conducted the original sitting decides each
        // officer, consents to the note, and signs it. Signing is the operative
        // act: it is what releases the held promotion.
        DemoActor::as($member, function () use ($circulation, $member): void {
            $live = $circulation->refresh()->load('items');

            foreach ($live->items as $item) {
                DemoClock::at('2026-07-20 11:15', fn () => $this->commission->decide(
                    $live,
                    $item,
                    $member,
                    CirculationDecision::Consented,
                ));
            }

            DemoClock::at('2026-07-22 10:05', fn () => $this->commission->consent($live->refresh(), $member, DemoActor::request()));
            DemoClock::at('2026-07-25 11:30', fn () => $this->commission->sign($live->refresh(), $member, DemoActor::request()));
            DemoClock::at('2026-07-25 11:45', fn () => $this->circulationDocuments->generate($live->refresh(), $member));
        });

        return $circulation->refresh();
    }

    // ------------------------------------------------------------------
    // Shared steps
    // ------------------------------------------------------------------

    /**
     * S31–S34 — open the sitting, calculate, optionally record a correction and
     * recalculate, approve the result, and generate the documents.
     *
     * @param  array<string, mixed>  $timing
     */
    private function conductSitting(DpcProposal $proposal, User $member, array $timing): DpcProceeding
    {
        return DemoActor::as($member, function () use ($proposal, $member, $timing): DpcProceeding {
            $proceeding = DemoClock::at(
                $timing['opened_at'],
                fn (): DpcProceeding => $this->proceedings->open($proposal, $member, DemoActor::request()),
            );

            DemoClock::at($timing['calculated_at'], fn () => $this->calculations->calculate($proceeding, $member));

            if (isset($timing['correction'])) {
                $correction = $timing['correction'];

                DemoClock::at($correction['at'], fn () => $this->corrections->record(
                    $proceeding,
                    $member,
                    $correction['field'],
                    $correction['sn'],
                    $correction['year'],
                    $correction['value'],
                    $correction['reason'],
                ));

                DemoClock::at($correction['recalculated_at'], fn () => $this->calculations->calculate($proceeding->refresh(), $member));
            }

            DemoClock::at($timing['approved_at'], fn () => $this->results->approve(
                $proceeding->refresh(),
                $member,
                DemoActor::request(),
                promotionAsOnYear: 2026,
            ));

            DemoClock::at($timing['documents_at'], fn () => $this->documents->generate($proceeding->refresh(), $member));

            return $proceeding->refresh();
        });
    }

    /**
     * Release the result documents to the department.
     *
     * Publication is refused while a result is provisional — a business rule
     * affecting it has not been settled. The seeded charts are chosen so that
     * every reserved category with a vacancy has enough eligible officers and
     * no ineligible officer sits inside the unruled relaxation band, which is
     * what keeps the flag clear.
     *
     * A failure here is therefore a signal that the seeded data has drifted,
     * and it is allowed to stop the seeder. Swallowing it would leave the demo
     * with a promotion list the department can never be shown, discovered on
     * stage rather than here.
     */
    private function publish(DpcProceeding $proceeding, User $member, string $at): void
    {
        DemoActor::as($member, fn () => DemoClock::at($at, fn () => $this->documents->publish($proceeding, $member)));
    }

    /**
     * The department's DPC Committee (S15): the Administrative Head in the
     * chair, the Authorized Officer as member, and one external member from
     * another department.
     *
     * @return list<array<string, mixed>>
     */
    private function committee(User $ah, User $ao): array
    {
        return [
            ['user' => $ah, 'capacity' => CommitteeCapacity::Chairman],
            ['user' => $ao, 'capacity' => CommitteeCapacity::Member],
            [
                'user' => null,
                'name' => 'Shri Sanjay Kumar Agrawal',
                'designation' => 'Deputy Secretary, General Administration Department',
                'mobile' => '9000009001',
                'email' => 'ds.gad@cg.gov.in',
                'capacity' => CommitteeCapacity::Member,
            ],
        ];
    }

    /**
     * The officer list with CR grades replaced — how a Review's corrected
     * tabulation chart differs from the original it copied.
     *
     * @param  list<array<string, mixed>>  $officers
     * @param  list<array{sn:int, year:int, grade:string}>  $corrections
     * @return list<array<string, mixed>>
     */
    private function withCorrectedGrades(array $officers, array $corrections): array
    {
        foreach ($corrections as $correction) {
            $column = array_search($correction['year'], DemoChart::CR_YEARS, true);

            if ($column === false) {
                continue;
            }

            foreach ($officers as $index => $officer) {
                if ($officer['sn'] === $correction['sn']) {
                    $officers[$index]['grades'][$column] = $correction['grade'];
                    $officers[$index]['note'] = 'CR grade for '.$correction['year'].' corrected under the Review DPC.';
                }
            }
        }

        return $officers;
    }

    private function officer(Department $department, Role $role): User
    {
        return User::query()
            ->where('department_id', $department->id)
            ->whereHas('roles', fn ($query) => $query->where('name', $role->value))
            ->firstOrFail();
    }
}
