<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\CommitteeConfiguration;
use App\Domain\Master\Models\CommitteeType;
use App\Domain\Master\Models\PayScale;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\ReservationPolicy;
use App\Domain\Master\Models\ReservationRoster;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\PreDpc\Services\PreDpcProposalService;
use App\Enums\MasterStatus;
use App\Enums\Role;
use App\Enums\ServiceClass;
use Illuminate\Database\Seeder;

/**
 * The masters a DPC needs before it can exist: the promotion ladder, the
 * recruitment rules that govern it, the reservation policy and roster, and the
 * committee composition rules.
 *
 * **The cadres and recruitment rules are not inserted.** They are produced by
 * running an actual Pre-DPC Proposal (PPT S7–S11) through the real service and
 * approving it, because approval is what projects a Pre-DPC into the Cadre and
 * Recruitment Rule masters (architect Q1 — single source of truth). Seeding
 * cadres directly would produce masters with no approved Pre-DPC behind them,
 * and "where did this cadre come from?" is a question the demo has to be able
 * to answer.
 */
class DemoMasterSeeder extends Seeder
{
    /**
     * The one promotion ladder the demo runs on: the School Education
     * Department's nine sanctioned posts, from Teacher to Director of Public
     * Instruction. Each entry is a sanctioned post; `feeders` names the post or
     * posts immediately below it, which is what makes the ladder a chain rather
     * than a list.
     *
     * Every rung is here on purpose. Nine posts give eight distinct promotion
     * pairs, and the demo needs one pair per DPC — the two journey DPCs plus
     * the six parked in intermediate workflow states — so that no two DPCs on
     * a list screen are promotions to the same cadre. `Lecturer` deliberately
     * carries **two** feeders: a recruitment rule with a single feeder makes
     * the Recruitment Rule screen look like it can only ever hold one, and the
     * real rules routinely do not.
     *
     * @var array{go:string, effective_from:string, posts:list<array{name:string, class:ServiceClass, scale:string, dr:int, pr:int, feeders?:list<array{name:string, pct:int, experience:int, qualification:string}>}>}
     */
    public const LADDER = [
        'go' => 'F-2-14/2025/20-Three',
        'effective_from' => '2026-01-01',
        'posts' => [
            ['name' => 'Teacher', 'class' => ServiceClass::III, 'scale' => 'L10', 'dr' => 100, 'pr' => 0],
            ['name' => 'Head Master, Middle School', 'class' => ServiceClass::III, 'scale' => 'L10', 'dr' => 0, 'pr' => 100, 'feeders' => [
                ['name' => 'Teacher', 'pct' => 100, 'experience' => 5, 'qualification' => 'B.Ed.'],
            ]],
            ['name' => 'Lecturer', 'class' => ServiceClass::II, 'scale' => 'L11', 'dr' => 50, 'pr' => 50, 'feeders' => [
                ['name' => 'Teacher', 'pct' => 25, 'experience' => 8, 'qualification' => 'Post-Graduate with B.Ed.'],
                ['name' => 'Head Master, Middle School', 'pct' => 25, 'experience' => 4, 'qualification' => 'Post-Graduate with B.Ed.'],
            ]],
            ['name' => 'Principal, Higher Secondary School', 'class' => ServiceClass::II, 'scale' => 'L12', 'dr' => 25, 'pr' => 75, 'feeders' => [
                ['name' => 'Lecturer', 'pct' => 75, 'experience' => 5, 'qualification' => 'Post-Graduate with B.Ed.'],
            ]],
            ['name' => 'Block Education Officer', 'class' => ServiceClass::II, 'scale' => 'L12', 'dr' => 0, 'pr' => 100, 'feeders' => [
                ['name' => 'Principal, Higher Secondary School', 'pct' => 100, 'experience' => 5, 'qualification' => 'Post-Graduate'],
            ]],
            ['name' => 'District Education Officer', 'class' => ServiceClass::I, 'scale' => 'L12', 'dr' => 0, 'pr' => 100, 'feeders' => [
                ['name' => 'Principal, Higher Secondary School', 'pct' => 100, 'experience' => 7, 'qualification' => 'Post-Graduate'],
            ]],
            ['name' => 'Joint Director, Education', 'class' => ServiceClass::I, 'scale' => 'L13', 'dr' => 0, 'pr' => 100, 'feeders' => [
                ['name' => 'District Education Officer', 'pct' => 100, 'experience' => 5, 'qualification' => 'Post-Graduate'],
            ]],
            ['name' => 'Additional Director, Education', 'class' => ServiceClass::I, 'scale' => 'L13A', 'dr' => 0, 'pr' => 100, 'feeders' => [
                ['name' => 'Joint Director, Education', 'pct' => 100, 'experience' => 4, 'qualification' => 'Post-Graduate'],
            ]],
            ['name' => 'Director, Public Instruction', 'class' => ServiceClass::I, 'scale' => 'L14', 'dr' => 0, 'pr' => 100, 'feeders' => [
                ['name' => 'Additional Director, Education', 'pct' => 100, 'experience' => 3, 'qualification' => 'Post-Graduate'],
            ]],
        ],
    ];

    /**
     * The cadres a reservation roster is maintained against.
     *
     * Both journey DPCs get one, so each concluded promotion has a roster
     * standing behind its category-wise vacancy breakdown rather than only the
     * apex cadre having one.
     *
     * @var list<string>
     */
    private const ROSTER_CADRES = [
        'Joint Director, Education',
        'Principal, Higher Secondary School',
    ];

    public function run(PreDpcProposalService $preDpc): void
    {
        $this->reservationPolicies();
        $this->committeeMasters();

        $department = Department::query()->where('code', 'SE')->firstOrFail();

        $this->approvedPreDpc($preDpc, $department, self::LADDER);

        foreach (self::ROSTER_CADRES as $cadre) {
            $this->reservationRoster($department, $cadre);
        }
    }

    /**
     * Effective-dated reservation percentages (architect Q8) — the policy layer
     * the roster is built from. Unreserved is included with a zero percentage
     * so the master lists every category rather than implying UR is absent.
     */
    private function reservationPolicies(): void
    {
        foreach (['SC' => 13, 'ST' => 32, 'OBC' => 27, 'EWS' => 10, 'UR' => 0] as $code => $percentage) {
            $category = ReservationCategory::query()->where('code', $code)->first();

            if ($category === null) {
                continue;
            }

            ReservationPolicy::query()->updateOrCreate(
                ['reservation_category_id' => $category->id, 'effective_from' => '2019-01-01'],
                [
                    'percentage' => $percentage,
                    'effective_to' => null,
                    'status' => MasterStatus::Active->value,
                ],
            );
        }
    }

    /**
     * A second committee type beside the base seeder's DPC Committee, so the
     * Committee Type and Committee Configuration screens each show a list
     * rather than a single row.
     */
    private function committeeMasters(): void
    {
        $active = MasterStatus::Active->value;

        $type = CommitteeType::query()->updateOrCreate(
            ['code' => 'departmental_screening_committee'],
            ['name_en' => 'Departmental Screening Committee', 'name_hi' => 'विभागीय स्क्रीनिंग समिति', 'status' => $active],
        );

        CommitteeConfiguration::query()->updateOrCreate(
            ['committee_type_id' => $type->id],
            [
                'min_members' => 2,
                'max_members' => 4,
                'chairman_mandatory' => true,
                'quorum' => 2,
                'voting_method' => 'chairman_decision',
                'majority_type' => 'not_applicable',
                'tie_break_rule' => 'chairman_decision',
                'digital_signature_required' => true,
                'remarks_required' => true,
                'external_member_allowed' => false,
                'secretary_required' => true,
                'effective_from' => '2026-01-01',
                'status' => $active,
            ],
        );
    }

    /**
     * Prepare, submit and approve a Pre-DPC through the real workflow. Approval
     * is what creates the department's Cadre rows and the versioned Recruitment
     * Rule for each of them.
     *
     * @param  array{go:string, effective_from:string, posts:list<array<string, mixed>>}  $ladder
     */
    private function approvedPreDpc(PreDpcProposalService $preDpc, Department $department, array $ladder): void
    {
        if (PreDpcProposal::query()->where('department_id', $department->id)->exists()) {
            return;
        }

        $ao = $this->officer($department, Role::AuthorizedOfficer);
        $ah = $this->officer($department, Role::AdministrativeHead);
        $scales = PayScale::query()->pluck('id', 'code');

        $posts = array_map(static function (array $post) use ($scales): array {
            $feeders = array_map(static fn (array $feeder): array => [
                'feeder_name' => $feeder['name'],
                'promotion_percentage' => $feeder['pct'],
                'min_experience_years' => $feeder['experience'],
                'min_qualification' => $feeder['qualification'],
            ], $post['feeders'] ?? []);

            return [
                'post_name' => $post['name'],
                'class_level' => $post['class']->value,
                'pay_scale_id' => $scales[$post['scale']] ?? null,
                'direct_recruitment_pct' => $post['dr'],
                'promotion_recruitment_pct' => $post['pr'],
                'feeders' => $feeders,
            ];
        }, $ladder['posts']);

        DemoActor::as($ao, function () use ($preDpc, $department, $ao, $ah, $ladder, $posts): void {
            $proposal = DemoClock::at('2026-01-05 10:20', fn () => $preDpc->createDraft($department, $ao, [
                'government_order_number' => $ladder['go'],
                'effective_from' => $ladder['effective_from'],
                'posts' => $posts,
            ]));

            DemoClock::at('2026-01-08 15:45', fn () => $preDpc->submit($proposal, $ao, DemoActor::request()));

            DemoActor::as($ah, fn () => DemoClock::at(
                '2026-01-15 11:30',
                fn () => $preDpc->approve($proposal->refresh(), $ah, DemoActor::request()),
            ));
        });
    }

    /**
     * A 100-point reservation roster for the department's apex cadre.
     *
     * The point-to-category allocation is **illustrative demo data, not a
     * statutory roster**: reserved points are spread evenly across the cycle in
     * proportion to the seeded policy percentages. The real allocation is a
     * published government roster and is maintained by the CGPSC Admin on the
     * Reservation Roster screen — which is precisely the screen this data
     * exists to demonstrate.
     */
    private function reservationRoster(Department $department, string $cadreName): void
    {
        $cadre = Cadre::query()
            ->where('department_id', $department->id)
            ->where('name', $cadreName)
            ->first();

        if ($cadre === null) {
            return;
        }

        $roster = ReservationRoster::query()->updateOrCreate(
            ['department_id' => $department->id, 'cadre_id' => $cadre->id, 'name' => '100-Point Roster (Illustrative)'],
            [
                'size' => 100,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
                'status' => MasterStatus::Active->value,
            ],
        );

        if ($roster->points()->exists()) {
            return;
        }

        $categories = ReservationCategory::query()->pluck('id', 'code');
        $allocation = $this->rosterAllocation(['ST' => 32, 'SC' => 13, 'OBC' => 27, 'EWS' => 10]);

        for ($point = 1; $point <= 100; $point++) {
            $code = $allocation[$point] ?? 'UR';

            $roster->points()->create([
                'point_no' => $point,
                'reservation_category_id' => $categories[$code],
            ]);
        }
    }

    /**
     * Spread each category's share evenly over the 100-point cycle, skipping a
     * point that is already taken. Deterministic, so the roster is identical on
     * every re-seed.
     *
     * @param  array<string, int>  $shares
     * @return array<int, string>
     */
    private function rosterAllocation(array $shares): array
    {
        $allocation = [];

        foreach ($shares as $code => $share) {
            $spacing = 100 / $share;

            for ($i = 0; $i < $share; $i++) {
                $point = (int) round($i * $spacing) + 1;

                while ($point <= 100 && isset($allocation[$point])) {
                    $point++;
                }

                if ($point <= 100) {
                    $allocation[$point] = $code;
                }
            }
        }

        return $allocation;
    }

    private function officer(Department $department, Role $role): User
    {
        return User::query()
            ->where('department_id', $department->id)
            ->whereHas('roles', fn ($query) => $query->where('name', $role->value))
            ->firstOrFail();
    }
}
