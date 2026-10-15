<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Services\AllotmentService;
use App\Domain\Cgpsc\Services\SchedulingService;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\CommitteeCapacity;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Proceeding\Services\ProceedingCalculationService;
use App\Domain\Proceeding\Services\ProceedingService;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * One DPC parked in each workflow state, so every list screen in the portal has
 * something on it and every "what happens next" button has something to act on.
 *
 * {@see DemoJourneySeeder} shows two DPCs taken end to end. This seeder shows
 * the portal on an ordinary working day: a proposal still being drafted, one
 * waiting on the Administrative Head, one waiting to be allotted, one waiting to
 * be accepted, one scheduled, and one sitting open with the result calculated
 * but not yet approved.
 *
 * **These are the DPCs the presenter acts on live.** The journey DPCs are
 * finished and cannot be submitted, allotted or accepted again, so every step
 * of the workflow that the demo performs on stage rather than describes is
 * performed on one of these six. That is why each is stopped exactly one step
 * short of the button being demonstrated.
 *
 * Each is built through the same services as the main journey and stopped where
 * it is meant to stop — a Draft is a draft because nobody submitted it, not
 * because a column was set to `draft`.
 *
 * Every one of them is a School Education Department DPC on a distinct rung of
 * the department's own promotion ladder, so no two rows on a list screen are
 * promotions to the same post and nothing on screen belongs to a department
 * the presenter has not introduced.
 */
class DemoPipelineSeeder extends Seeder
{
    public function __construct(
        private readonly DemoDpcBuilder $builder,
        private readonly AllotmentService $allotments,
        private readonly SchedulingService $scheduling,
        private readonly ProceedingService $proceedings,
        private readonly ProceedingCalculationService $calculations,
    ) {}

    public function run(): void
    {
        $this->draftAwaitingCompletion();
        $this->submittedAwaitingAdministrativeHead();
        $this->approvedAwaitingAllotment();
        $this->allottedAwaitingAcceptance();
        $this->scheduledAwaitingSitting();
        $this->sittingOpenAwaitingApproval();
    }

    /**
     * Principal → Block Education Officer, still on the Authorized Officer's
     * desk. Every section is filled, so the demo can show the completeness
     * checklist all green and press SUBMIT live.
     */
    private function draftAwaitingCompletion(): void
    {
        $this->builder->draft($this->department(), $this->officer(Role::AuthorizedOfficer), [
            'from' => 'Principal, Higher Secondary School',
            'to' => 'Block Education Officer',
            'proposal_date' => '2026-08-03',
            'drafted_at' => '2026-08-03 11:10',
            // Balanced by the vacancy directive of 2026-08-22: sanctioned minus
            // working officers must equal the vacancy breakdown. These pipeline
            // DPCs are Class-II/III pairs, which the deck gives no zone formula,
            // so the seniority list keeps every officer listed below.
            'sanctioned' => 4,
            'vacancies' => [['clear', 'UR', 1]],
            'committee' => $this->committee(),
            'working' => DemoOfficers::generic([
                'Shri Kamal Narayan Sahu', 'Smt. Bharti Sonwani', 'Shri Yogesh Kumar Dhruw',
            ]),
            'officers' => DemoOfficers::generic([
                'Shri Deepak Kumar Sahu', 'Smt. Anjali Shrivastava', 'Shri Ganesh Ram Bhaskar',
                'Smt. Sushma Uikey', 'Shri Rakesh Kumar Nirmalkar', 'Smt. Priyanka Mishra',
            ]),
        ]);
    }

    /**
     * Teacher → Head Master, submitted and waiting on the Administrative Head,
     * so the AH review walkthrough (S21–S25) has a live proposal to walk through
     * and approve on stage — which is also where the DPC ID gets minted in front
     * of the audience.
     */
    private function submittedAwaitingAdministrativeHead(): void
    {
        $ao = $this->officer(Role::AuthorizedOfficer);

        $proposal = $this->builder->draft($this->department(), $ao, [
            'from' => 'Teacher',
            'to' => 'Head Master, Middle School',
            'proposal_date' => '2026-07-15',
            'drafted_at' => '2026-07-15 10:00',
            // Balanced by the vacancy directive of 2026-08-22: sanctioned minus
            // working officers must equal the vacancy breakdown. These pipeline
            // DPCs are Class-II/III pairs, which the deck gives no zone formula,
            // so the seniority list keeps every officer listed below.
            'sanctioned' => 4,
            'vacancies' => [['clear', 'UR', 1]],
            'committee' => $this->committee(),
            'working' => DemoOfficers::generic([
                'Shri Ravi Prakash Sahu', 'Smt. Kiran Bala Tandon', 'Shri Sunil Kumar Dewangan',
            ]),
            'officers' => DemoOfficers::generic([
                'Shri Ashwani Kumar Sahu', 'Smt. Neelam Chandrakar', 'Shri Bhola Ram Sonkar',
                'Smt. Rajkumari Netam', 'Shri Devnath Yadav', 'Smt. Sarita Tiwari',
            ]),
        ]);

        $this->builder->submit($proposal, $ao, '2026-08-05 16:20');
    }

    /**
     * Teacher → Lecturer, approved and forwarded, waiting in the Commission's
     * allotment queue (S26/S28). This is the DPC the Chairman allots live.
     */
    private function approvedAwaitingAllotment(): void
    {
        $this->approved([
            'from' => 'Teacher',
            'to' => 'Lecturer',
            'proposal_date' => '2026-07-02',
            'drafted_at' => '2026-07-02 10:40',
            'submitted_at' => '2026-07-10 15:30',
            'approved_at' => '2026-07-14 11:15',
            // Balanced by the vacancy directive of 2026-08-22: sanctioned minus
            // working officers must equal the vacancy breakdown. These pipeline
            // DPCs are Class-II/III pairs, which the deck gives no zone formula,
            // so the seniority list keeps every officer listed below.
            'sanctioned' => 4,
            'vacancies' => [['from_retirement', 'SC', 1]],

            /*
             | Lecturer is a 50/50 post, so four sanctioned chairs are two
             | promotion and two direct. Two of the three sitting officers are
             | direct recruits, which leaves one promotion chair free — without
             | that this DPC has nothing it is permitted to fill, and the
             | completeness gate refuses it for having no vacancy.
             */
            'working' => DemoOfficers::generic(
                ['Shri Vinay Kumar Sahu', 'Smt. Sushma Bhagat', 'Shri Rajendra Prasad Verma'],
                'Post-Graduate with B.Ed.',
                ['promotion', 'direct', 'direct'],
            ),
            'officers' => DemoOfficers::generic([
                'Shri Alok Kumar Mishra', 'Smt. Renuka Sharma', 'Shri Shailendra Kumar Bansod',
                'Smt. Pramila Kujur', 'Shri Nitin Kumar Sahu', 'Smt. Archana Dubey',
            ]),
        ]);
    }

    /**
     * Principal → District Education Officer, allotted to a Member who has not
     * accepted it yet (S30). This is the DPC that Member accepts and schedules
     * live.
     */
    private function allottedAwaitingAcceptance(): void
    {
        $proposal = $this->approved([
            'from' => 'Principal, Higher Secondary School',
            'to' => 'District Education Officer',
            'proposal_date' => '2026-06-08',
            'drafted_at' => '2026-06-08 09:45',
            'submitted_at' => '2026-06-16 14:20',
            'approved_at' => '2026-06-19 10:05',
            // Balanced by the vacancy directive of 2026-08-22: sanctioned minus
            // working officers must equal the vacancy breakdown. These pipeline
            // DPCs are Class-II/III pairs, which the deck gives no zone formula,
            // so the seniority list keeps every officer listed below.
            'allottee' => 'member3@cgpsc.gov.in',
            'sanctioned' => 4,
            'vacancies' => [['clear', 'ST', 1]],
            'working' => DemoOfficers::generic([
                'Shri Harish Chandra Sahu', 'Smt. Meenakshi Rao', 'Shri Umesh Kumar Patel',
            ]),
            'officers' => DemoOfficers::generic([
                'Shri Sanjay Kumar Sahu', 'Smt. Poonam Agrawal', 'Shri Lakhan Lal Bharti',
                'Smt. Savitri Markam', 'Shri Kishore Kumar Sahu', 'Smt. Nidhi Shukla',
            ]),
        ]);

        $this->allot($proposal, 'member3@cgpsc.gov.in', '2026-06-24 11:30', '2026-09-02');
    }

    /**
     * Additional Director → Director of Public Instruction: accepted and
     * scheduled, waiting for the sitting to open (S31). The presenter opens this
     * sitting live and presses CALCULATE on it.
     *
     * The apex post is a single sanctioned post and it is vacant, which is a
     * legitimate — and common — reason to hold a DPC at all. It also puts the
     * single-vacancy zone rule on screen: one vacancy in a Class-I to Class-I
     * promotion fixes the zone of consideration at five rather than computing
     * it, so all five officers below are inside it.
     */
    private function scheduledAwaitingSitting(): void
    {
        $proposal = $this->approved([
            'from' => 'Additional Director, Education',
            'to' => 'Director, Public Instruction',
            'proposal_date' => '2026-06-22',
            'drafted_at' => '2026-06-22 10:10',
            'submitted_at' => '2026-06-30 16:45',
            'approved_at' => '2026-07-03 12:20',
            'allottee' => 'member4@cgpsc.gov.in',
            'sanctioned' => 1,
            'vacancies' => [['clear', 'UR', 1]],
            'working' => [],
            'officers' => DemoOfficers::generic([
                'Shri Mahesh Kumar Sahu', 'Smt. Vidya Chandrakar', 'Shri Ashok Kumar Jain',
                'Smt. Phoolbasan Netam', 'Shri Dhananjay Kumar Sahu',
            ]),
        ]);

        $member = $this->allot($proposal, 'member4@cgpsc.gov.in', '2026-07-08 10:30', '2026-09-10');

        $this->acceptAndSchedule($proposal, $member, '2026-07-10 09:40', '2026-07-14 15:00', '2026-09-10', '11:30');
    }

    /**
     * Joint Director → Additional Director: the sitting is open and the result
     * calculated, but not yet approved. This is the DPC to open on stage for the
     * S33 workspace — the evaluation chart, the corrections panel, and the
     * CALCULATE button — because everything is in place and nothing is decided.
     */
    private function sittingOpenAwaitingApproval(): void
    {
        $proposal = $this->approved([
            'from' => 'Joint Director, Education',
            'to' => 'Additional Director, Education',
            'proposal_date' => '2026-05-11',
            'drafted_at' => '2026-05-11 11:00',
            'submitted_at' => '2026-05-20 16:10',
            'approved_at' => '2026-05-23 10:50',
            // Balanced by the vacancy directive of 2026-08-22: sanctioned minus
            // working officers must equal the vacancy breakdown. These pipeline
            // DPCs are Class-II/III pairs, which the deck gives no zone formula,
            // so the seniority list keeps every officer listed below.
            'allottee' => 'member5@cgpsc.gov.in',

            /*
             | **Ten, because Additional Director is a ten-chair post.** The
             | sanctioned strength lives on the Department Post Mapping, so it is one
             | master record shared by every DPC on the post — including the
             | demonstration's own Block Education Officer → Additional Director
             | sample record, which is built for ten. A spec that set three here would
             | leave that number in the master and the sample data would not balance.
             |
             | Nine chairs held leaves one vacancy, which keeps the Zone of
             | Consideration at 2(1)+4 = 6 and inside the six officers below.
             */
            'sanctioned' => 10,
            'vacancies' => [['from_promotion', 'OBC', 1]],
            'working' => DemoOfficers::generic([
                'Shri Girish Kumar Sahu', 'Smt. Nandini Verma', 'Shri Bhola Prasad Sahu',
                'Smt. Draupadi Netam', 'Shri Kailash Chandra Jain', 'Smt. Sarla Bai Uikey',
                'Shri Toran Lal Dhruw', 'Smt. Shobha Rani Das', 'Shri Prem Kumar Bagh',
            ]),
            'officers' => DemoOfficers::generic([
                'Shri Vikas Kumar Sahu', 'Smt. Deepika Rathore', 'Shri Shyam Sundar Bharti',
                'Smt. Kamla Bai Uikey', 'Shri Anup Kumar Sahu', 'Smt. Ritu Khandelwal',
            ]),
        ]);

        $member = $this->allot($proposal, 'member5@cgpsc.gov.in', '2026-05-27 12:00', '2026-08-12');

        $this->acceptAndSchedule($proposal, $member, '2026-05-29 10:15', '2026-06-02 14:30', '2026-08-12', '11:00');

        DemoActor::as($member, function () use ($proposal, $member): void {
            $proceeding = DemoClock::at(
                '2026-08-12 11:04',
                fn () => $this->proceedings->open($proposal, $member, DemoActor::request()),
            );

            DemoClock::at('2026-08-12 11:26', fn () => $this->calculations->calculate($proceeding, $member));
        });
    }

    // ------------------------------------------------------------------
    // Shared steps
    // ------------------------------------------------------------------

    /**
     * Draft → submit → approve, under the department's own officers.
     *
     * @param  array<string, mixed>  $spec
     */
    private function approved(array $spec): DpcProposal
    {
        return $this->builder->approved(
            $this->department(),
            $this->officer(Role::AuthorizedOfficer),
            $this->officer(Role::AdministrativeHead),
            $spec + ['committee' => $this->committee($spec['allottee'] ?? null)],
        );
    }

    /**
     * S28 — the Chairman allots a forwarded DPC to a Member, and returns that
     * Member so the caller can carry on as them.
     */
    private function allot(DpcProposal $proposal, string $memberEmail, string $at, string $proposedDate): User
    {
        $chairman = User::query()->where('email', 'chairman@cgpsc.gov.in')->firstOrFail();
        $member = User::query()->where('email', $memberEmail)->firstOrFail();

        DemoActor::as($chairman, fn () => DemoClock::at($at, fn () => $this->allotments->allot(
            $proposal,
            $member,
            $chairman,
            DemoActor::request(),
            Carbon::parse($proposedDate),

            /*
             | The date the DPC must be concluded by (change register of 2026-08-26,
             | item 9). A month past the proposed sitting, so the demonstration shows
             | a real deadline with room in it — an allotment already overdue would
             | be swept straight back by `dpc:return-expired-allotments` and the
             | walkthrough would lose its DPC.
             */
            Carbon::parse($proposedDate)->addMonth(),
        )));

        return $member;
    }

    /**
     * S30 — the Member accepts the allotment, then fixes the date and time of
     * the sitting.
     */
    private function acceptAndSchedule(
        DpcProposal $proposal,
        User $member,
        string $acceptedAt,
        string $scheduledAt,
        string $sittingDate,
        string $sittingTime,
    ): void {
        DemoActor::as($member, function () use ($proposal, $member, $acceptedAt, $scheduledAt, $sittingDate, $sittingTime): void {
            DemoClock::at($acceptedAt, fn () => $this->scheduling->accept($proposal, $member, DemoActor::request()));

            DemoClock::at($scheduledAt, fn () => $this->scheduling->schedule(
                $proposal,
                $member,
                DemoActor::request(),
                Carbon::parse($sittingDate),
                $sittingTime,
            ));
        });
    }

    /**
     * The department's own DPC Committee: Administrative Head in the chair,
     * Authorized Officer as member.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * @param  string|null  $allotteeEmail  The Member this DPC will be allotted to, who must hold a seat on it
     * @return list<array<string, mixed>>
     */
    private function committee(?string $allotteeEmail = null): array
    {
        /*
         | Ordinary members only, and three of them — the CGPSC Chairman's seat is
         | placed by `DpcCommitteeService` (item 9), and the size rule asks for
         | three besides it.
         |
         | The second seat is the **allotted Member** wherever this DPC gets as far
         | as an allotment, because `AllotmentService` refuses to allot a DPC to
         | somebody who is not already seated on its committee. Where the spec
         | names no allottee the DPC never leaves the department, and the
         | Authorized Officer keeps the seat.
         */
        $second = $allotteeEmail === null
            ? $this->officer(Role::AuthorizedOfficer)
            : User::query()->where('email', $allotteeEmail)->firstOrFail();

        /*
         | The attribute spread matches the journey committees, and it has to:
         | the composition rules ask this committee to represent **ST**, **Bhar
         | Sadhak Sachiv** and **Vibhag Sachiv** as well as SC, OBC and a woman,
         | and three seats can only carry six requirements if some seats carry
         | several. Seeded with only SC, female and OBC, these proposals were
         | refused at submission for three unrepresented attributes at once.
         */
        return [
            [
                'user' => $this->officer(Role::AdministrativeHead),
                'capacity' => CommitteeCapacity::Member,
                'is_female' => true, 'is_st' => true, 'is_bhar_sadhak_sachiv' => true,
            ],
            ['user' => $second, 'capacity' => CommitteeCapacity::Member, 'is_sc' => true],
            [
                'user' => null,
                'name' => 'Shri Sanjay Kumar Agrawal',
                'designation' => 'Deputy Secretary, General Administration Department',
                'mobile' => '9000009002',
                'capacity' => CommitteeCapacity::Member,
                'is_obc' => true, 'is_vibhag_sachiv' => true,
            ],
        ];
    }

    private function department(): Department
    {
        return Department::query()->where('code', 'SE')->firstOrFail();
    }

    private function officer(Role $role): User
    {
        // The posting is the assignment, not a column on the user (change item 2).
        return User::query()
            ->whereHas('assignments', fn ($query) => $query
                ->whereNotNull('active_flag')
                ->where('department_id', $this->department()->id)
                ->where('role', $role->value))
            ->firstOrFail();
    }
}
