<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Authentication\Models\User;
use App\Domain\Committee\Models\CgpscChairman;
use App\Domain\Committee\Services\CgpscChairmanService;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DepartmentPostOrder;
use App\Domain\Master\Models\PayScale;
use App\Domain\Master\Models\Qualification;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\ReservationPolicy;
use App\Domain\Master\Models\ReservationRoster;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\PreDpc\Services\PreDpcProposalService;
use App\Enums\MasterStatus;
use App\Enums\Role;
use App\Enums\ServiceClass;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The masters a DPC needs before it can exist: the promotion ladder, the
 * recruitment rules that govern it, the reservation policy and roster, and the
 * committee composition rules.
 *
 * **The recruitment rules are not inserted.** They are produced by running
 * actual Pre-DPC Proposals (PPT S7–S11) through the real service and approving
 * them, because approval is what projects a Pre-DPC into the Recruitment Rule
 * master (architect Q1 — single source of truth). Seeding rules directly would
 * produce masters with no approved Pre-DPC behind them, and "where did this rule
 * come from?" is a question the demo has to be able to answer.
 *
 * **The Department Posts *are* inserted, and the order of operations changed on
 * 2026-08-24.** A Pre-DPC is now one post pair (change items 8–11), so both posts
 * must already exist in the master for a proposal to name them — approval can no
 * longer be what mints them. Registering Department Posts is the CGPSC
 * Administrator's job under change item 1, so that is the sequence the demo
 * follows: the Administrator registers the ladder, then each department drafts and
 * approves one Pre-DPC per rung.
 */
class DemoMasterSeeder extends Seeder
{
    /**
     * The one promotion ladder the demo runs on: the School Education
     * Department's nine sanctioned posts, from Teacher to Director of Public
     * Instruction. Each entry is a sanctioned post; `promotionSources` names the post or
     * posts immediately below it, which is what makes the ladder a chain rather
     * than a list.
     *
     * Every rung is here on purpose. Nine posts give eight distinct promotion
     * pairs, and the demo needs one pair per DPC — the two journey DPCs plus
     * the six parked in intermediate workflow states — so that no two DPCs on
     * a list screen are promotions to the same post. `Lecturer` deliberately
     * carries **two** promotion sources: a recruitment rule with a single source makes
     * the Recruitment Rule screen look like it can only ever hold one, and the
     * real rules routinely do not.
     *
     * @var array{go:string, effective_from:string, posts:list<array{name:string, class:ServiceClass, scale:string, dr:int, pr:int, promotionSources?:list<array{name:string, pct:int, experience:int, qualification:string}>}>}
     */
    public const LADDER = [
        'go' => 'F-2-14/2025/20-Three',
        'effective_from' => '2026-01-01',
        'posts' => [
            ['name' => 'Teacher', 'class' => ServiceClass::III, 'scale' => 'L10', 'dr' => 100, 'pr' => 0],
            ['name' => 'Head Master, Middle School', 'class' => ServiceClass::III, 'scale' => 'L10', 'dr' => 0, 'pr' => 100, 'promotionSources' => [
                ['name' => 'Teacher', 'pct' => 100, 'experience' => 5, 'qualification' => 'B.Ed.'],
            ]],
            ['name' => 'Lecturer', 'class' => ServiceClass::II, 'scale' => 'L11', 'dr' => 50, 'pr' => 50, 'promotionSources' => [
                ['name' => 'Teacher', 'pct' => 25, 'experience' => 8, 'qualification' => 'Post-Graduate with B.Ed.'],
                ['name' => 'Head Master, Middle School', 'pct' => 25, 'experience' => 4, 'qualification' => 'Post-Graduate with B.Ed.'],
            ]],
            ['name' => 'Principal, Higher Secondary School', 'class' => ServiceClass::II, 'scale' => 'L12', 'dr' => 25, 'pr' => 75, 'promotionSources' => [
                ['name' => 'Lecturer', 'pct' => 75, 'experience' => 5, 'qualification' => 'Post-Graduate with B.Ed.'],
            ]],
            ['name' => 'Block Education Officer', 'class' => ServiceClass::II, 'scale' => 'L12', 'dr' => 0, 'pr' => 100, 'promotionSources' => [
                ['name' => 'Principal, Higher Secondary School', 'pct' => 100, 'experience' => 5, 'qualification' => 'Post-Graduate'],
            ]],
            ['name' => 'District Education Officer', 'class' => ServiceClass::I, 'scale' => 'L12', 'dr' => 0, 'pr' => 100, 'promotionSources' => [
                ['name' => 'Principal, Higher Secondary School', 'pct' => 100, 'experience' => 7, 'qualification' => 'Post-Graduate'],
            ]],
            ['name' => 'Joint Director, Education', 'class' => ServiceClass::I, 'scale' => 'L13', 'dr' => 0, 'pr' => 100, 'promotionSources' => [
                ['name' => 'District Education Officer', 'pct' => 100, 'experience' => 5, 'qualification' => 'Post-Graduate'],
            ]],
            /*
             | **Two feeders, and that is the point of this rung.**
             |
             | The ladder's own progression is Joint Director → Additional Director,
             | and the demonstration's sample record is **Block Education Officer →
             | Additional Director** (business instruction, 2026-09-07). Both are
             | real, so the order sanctions both and each DPC names which of them it
             | runs — `promotion_from_post_id` is stated on the proposal and never
             | inferred, because an order with two feeders does not say by itself.
             |
             | The two shares divide the 100% promotion quota; they do not change how
             | many chairs are promotion chairs, which is what `pr` decides.
             */
            ['name' => 'Additional Director, Education', 'class' => ServiceClass::I, 'scale' => 'L13A', 'dr' => 0, 'pr' => 100, 'promotionSources' => [
                ['name' => 'Joint Director, Education', 'pct' => 60, 'experience' => 4, 'qualification' => 'Post-Graduate'],
                ['name' => 'Block Education Officer', 'pct' => 40, 'experience' => 5, 'qualification' => 'Post-Graduate'],
            ]],
            ['name' => 'Director, Public Instruction', 'class' => ServiceClass::I, 'scale' => 'L14', 'dr' => 0, 'pr' => 100, 'promotionSources' => [
                ['name' => 'Additional Director, Education', 'pct' => 100, 'experience' => 3, 'qualification' => 'Post-Graduate'],
            ]],
        ],
    ];

    /**
     * The posts a reservation roster is maintained against.
     *
     * Both journey DPCs get one, so each concluded promotion has a roster
     * standing behind its category-wise vacancy breakdown rather than only the
     * apex post having one.
     *
     * @var list<string>
     */
    private const ROSTER_POSTS = [
        'Joint Director, Education',
        'Principal, Higher Secondary School',
    ];

    public function run(PreDpcProposalService $preDpc): void
    {
        $this->reservationPolicies();
        $this->cgpscChairman();

        $department = Department::query()->where('code', 'SE')->firstOrFail();

        $this->registerLadderPosts($department, self::LADDER);
        $this->approvedPreDpcs($preDpc, $department, self::LADDER);

        foreach (self::ROSTER_POSTS as $post) {
            $this->reservationRoster($department, $post);
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
     * The people the demonstration seats on DPC committees.
     *
     * **Not a master any more** (change register of 2026-08-26, item 5). Committee
     * membership is recorded on the DPC itself, so this is a fixture the DPC
     * builders draw on rather than rows in a `commission_members` table — the
     * attributes below are written onto `dpc_committee_members` when a committee is
     * composed.
     *
     * **The attribute spread is deliberate.** One person is SC *and* female *and*
     * Bhar Sadhak Sachiv at once, because the register's own worked example is
     * exactly that, and it is what lets a three-member committee satisfy five
     * requirements — the case item 8 exists to permit.
     *
     * These are illustrative names, like every other demo record. No real
     * Commission member is described here.
     *
     * @var list<array{email:string|null, name:string, post:string, sc:bool, st:bool, obc:bool, female:bool, bhar:bool, vibhag:bool}>
     */
    public const COMMITTEE_ROSTER = [
        ['email' => 'member1@cgpsc.gov.in', 'name' => 'Shri Brijesh Kumar Pandey', 'post' => 'Member, CGPSC',
            'sc' => false, 'st' => false, 'obc' => false, 'female' => false, 'bhar' => true, 'vibhag' => false],
        ['email' => 'member2@cgpsc.gov.in', 'name' => 'Smt. Kavita Deshmukh', 'post' => 'Member, CGPSC',
            'sc' => true, 'st' => false, 'obc' => false, 'female' => true, 'bhar' => true, 'vibhag' => false],
        ['email' => 'member3@cgpsc.gov.in', 'name' => 'Dr. Suresh Chandra Netam', 'post' => 'Member, CGPSC',
            'sc' => false, 'st' => true, 'obc' => false, 'female' => false, 'bhar' => false, 'vibhag' => true],
        ['email' => 'member4@cgpsc.gov.in', 'name' => 'Shri Mohan Lal Kashyap', 'post' => 'Member, CGPSC',
            'sc' => false, 'st' => false, 'obc' => true, 'female' => false, 'bhar' => false, 'vibhag' => false],
        ['email' => 'member5@cgpsc.gov.in', 'name' => 'Smt. Anita Bhonsle', 'post' => 'Member, CGPSC',
            'sc' => false, 'st' => false, 'obc' => true, 'female' => true, 'bhar' => false, 'vibhag' => false],
        // No login: a retired officer sitting as an expert. Committee membership
        // grants no role and needs no account, so this still works.
        ['email' => null, 'name' => 'Smt. Rajni Markam (Retd.)', 'post' => 'Former Secretary, Tribal Welfare',
            'sc' => false, 'st' => true, 'obc' => false, 'female' => true, 'bhar' => false, 'vibhag' => false],
    ];

    /**
     * Record who the CGPSC Chairman is (item 9).
     *
     * **Nothing else in the demonstration works without this.** Item 9 makes the
     * Chairman's seat mandatory on every committee, and `DpcCommitteeService`
     * refuses to compose one when no Chairman is recorded — so a demo database
     * without this row cannot raise a single DPC.
     *
     * Idempotent: `demo:seed` is run repeatedly against the same database, and
     * appointing a Chairman who is already serving would close their own tenure and
     * open an identical one every time.
     */
    private function cgpscChairman(): void
    {
        if (CgpscChairman::query()->serving()->exists()) {
            return;
        }

        $user = User::query()->where('email', 'chairman@cgpsc.gov.in')->first();

        app(CgpscChairmanService::class)->appoint([
            'user_id' => $user?->id,
            'name' => 'Dr. Ajay Kumar Shrivastava',
            'designation' => 'Chairman, Chhattisgarh Public Service Commission',
            'email' => 'chairman@cgpsc.gov.in',
            'mobile' => $user?->mobile,
        ]);
    }

    /**
     * Register the ladder in the Department Post master.
     *
     * The Administrator's step, and it has to come first: since change item 8–11 a
     * Pre-DPC names two existing posts rather than creating them, so nothing can
     * be drafted until the establishment is on record.
     *
     * `promotion_duration_years` is taken from the rung's own promotion-source
     * requirement, which is where the qualifying service was already recorded —
     * that is what makes item 5's auto-population produce a real number on the demo
     * rather than a dash. The Government Order and its effective date come from the
     * ladder itself, because one order establishes the whole establishment.
     *
     * @param  array{go:string, effective_from:string, posts:list<array<string, mixed>>}  $ladder
     */
    private function registerLadderPosts(Department $department, array $ladder): void
    {
        $scales = PayScale::query()->pluck('id', 'code');

        foreach ($ladder['posts'] as $post) {
            // The qualifying service an officer must serve *on this post* before
            // being promoted out of it is the experience the rung above demands of
            // it — the same figure, read from the other end.
            $duration = null;

            foreach ($ladder['posts'] as $above) {
                foreach ($above['promotionSources'] ?? [] as $feeder) {
                    if ($feeder['name'] === $post['name']) {
                        $duration = min($duration ?? PHP_INT_MAX, (int) $feeder['experience']);
                    }
                }
            }

            $mapping = DepartmentPost::query()->updateOrCreate(
                ['department_id' => $department->id, 'name' => $post['name']],
                [
                    'class_level' => $post['class']->value,
                    'pay_scale_id' => $scales[$post['scale']] ?? null,
                    'promotion_duration_years' => $duration,
                    'effective_from' => $ladder['effective_from'],
                    'status' => MasterStatus::Active->value,
                ],
            );

            /*
             | The Government Order that authorises the mapping.
             |
             | **A row since 2026-08-26** (change register item 10): the number and
             | the date left `department_posts` when a mapping became able to carry
             | several orders. The ladder names one order establishing the whole
             | establishment, which is how a Schedule-IV order is actually issued, so
             | every post cites the same one and the demo shows a real citation
             | rather than "Not recorded" on every row.
             |
             | `firstOrCreate` because `demo:seed` is run repeatedly against the same
             | database and `unique(department_post_id, government_order_number)`
             | would refuse the second attempt rather than ignoring it.
             */
            DepartmentPostOrder::query()->firstOrCreate(
                [
                    'department_post_id' => $mapping->id,
                    'government_order_number' => $ladder['go'],
                ],
                ['government_order_date' => $ladder['effective_from']],
            );
        }
    }

    /**
     * The master ids behind a ladder rung's qualification requirement.
     *
     * The fixture states a requirement the way a Schedule-IV order does —
     * `Post-Graduate with B.Ed.` — which is **two** qualifications, and item 11 is
     * what finally lets both be recorded. Split on "with" and matched against the
     * master; anything that matches nothing is dropped rather than guessed at, and
     * the free-text `min_qualification` column keeps the original wording either way.
     *
     * @param  Collection<string, int>  $master
     * @return list<int>
     */
    private function qualificationIdsFor(Collection $master, string $requirement): array
    {
        $ids = [];

        foreach (preg_split('/\s+with\s+/i', $requirement) ?: [] as $part) {
            $id = $master[trim($part)] ?? null;

            if ($id !== null && ! in_array($id, $ids, true)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Draft, submit and approve one Pre-DPC per **Promotion To Post** on the ladder.
     *
     * **One per post being filled, not one per pair** (change items 8–11). Eight
     * rungs have a feeder, so there are eight exercises, each separately numbered,
     * separately approved and separately active. `Lecturer` is one of them and names
     * **two** Promotion From Posts — Teacher and Head Master — which is the whole
     * point: a Schedule-IV promotion routinely draws on several feeders, and the
     * demo has to show one that does.
     *
     * **`pct` on the ladder is a share of the post; a source's is a share of the
     * promotion quota.** Lecturer is 50% direct and 50% promotion, and its two
     * feeders take 25% of the post each — which is 50% of the promotion quota each.
     * Converting between the two here rather than restating both in the fixture
     * keeps the ladder readable as what it is, a recruitment rule.
     *
     * @param  array{go:string, effective_from:string, posts:list<array<string, mixed>>}  $ladder
     */
    private function approvedPreDpcs(PreDpcProposalService $preDpc, Department $department, array $ladder): void
    {
        if (PreDpcProposal::query()->where('department_id', $department->id)->exists()) {
            return;
        }

        $ao = $this->officer($department, Role::AuthorizedOfficer);
        $ah = $this->officer($department, Role::AdministrativeHead);
        $posts = DepartmentPost::query()->where('department_id', $department->id)->pluck('id', 'name');
        $qualifications = Qualification::query()->pluck('id', 'name_en');
        $year = (int) Carbon::parse($ladder['effective_from'])->format('Y');

        foreach ($ladder['posts'] as $post) {
            $feeders = $post['promotionSources'] ?? [];

            if ($feeders === []) {
                continue;
            }

            $quota = (int) $post['pr'];

            $payload = [
                'dpc_year' => $year,
                'promotion_to_post_id' => $posts[$post['name']] ?? null,
                'direct_recruitment_pct' => (int) $post['dr'],
                'promotion_recruitment_pct' => $quota,
                'government_order_number' => $ladder['go'],
                'effective_from' => $ladder['effective_from'],
                'promotion_sources' => array_map(fn (array $feeder): array => [
                    'promotion_from_post_id' => $posts[$feeder['name']] ?? null,

                    // The feeder's share of the promotion quota. Guarded against a
                    // zero quota, which would be a ladder rung with no promotion at
                    // all and so no sources to divide.
                    'promotion_percentage' => $quota > 0
                        ? round((int) $feeder['pct'] / $quota * 100, 2)
                        : 0,

                    'min_experience_years' => (int) $feeder['experience'],
                    'experience_unit' => 'years',
                    /*
                     | **Plural since 2026-08-26** (change register item 11): a
                     | feeder may accept any of several qualifications. The demo
                     | shows both cases — one degree where the ladder names one, and
                     | the alternatives where it names a compound requirement — so
                     | the multi-select is visibly doing something.
                     */
                    'qualification_ids' => $this->qualificationIdsFor($qualifications, (string) $feeder['qualification']),
                ], $feeders),
            ];

            DemoActor::as($ao, function () use ($preDpc, $department, $ao, $ah, $payload): void {
                $proposal = DemoClock::at('2026-01-05 10:20', fn () => $preDpc->createDraft($department, $ao, $payload));

                DemoClock::at('2026-01-08 15:45', fn () => $preDpc->submit($proposal, $ao, DemoActor::request()));

                DemoActor::as($ah, fn () => DemoClock::at(
                    '2026-01-15 11:30',
                    fn () => $preDpc->approve($proposal->refresh(), $ah, DemoActor::request()),
                ));
            });
        }
    }

    /**
     * A 100-point reservation roster for the department's apex post.
     *
     * The point-to-category allocation is **illustrative demo data, not a
     * statutory roster**: reserved points are spread evenly across the cycle in
     * proportion to the seeded policy percentages. The real allocation is a
     * published government roster and is maintained by the CGPSC Admin on the
     * Reservation Roster screen — which is precisely the screen this data
     * exists to demonstrate.
     */
    private function reservationRoster(Department $department, string $postName): void
    {
        $post = DepartmentPost::query()
            ->where('department_id', $department->id)
            ->where('name', $postName)
            ->first();

        if ($post === null) {
            return;
        }

        $roster = ReservationRoster::query()->updateOrCreate(
            ['department_id' => $department->id, 'post_id' => $post->id, 'name' => '100-Point Roster (Illustrative)'],
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
        // The posting is the assignment, not a column on the user (change item 2).
        return User::query()
            ->whereHas('assignments', fn ($query) => $query
                ->whereNotNull('active_flag')
                ->where('department_id', $department->id)
                ->where('role', $role->value))
            ->firstOrFail();
    }
}
