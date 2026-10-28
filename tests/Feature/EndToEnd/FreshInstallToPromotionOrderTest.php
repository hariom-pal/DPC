<?php

declare(strict_types=1);

namespace Tests\Feature\EndToEnd;

use App\Domain\Authentication\Models\User;
use App\Domain\Committee\Models\CgpscChairman;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use App\Domain\Import\Models\ImportBatch;
use App\Domain\Master\Models\ClassMaster;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\Designation;
use App\Domain\Master\Models\District;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\PayScale;
use App\Domain\Master\Models\Qualification;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\PromotionOrder\Models\DpcPromotionOrder;
use App\Enums\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ReadsSentMail;
use Tests\TestCase;
use Throwable;

/**
 * The whole first DPC of a new department, from a fresh installation, through the
 * real routes (regression, 2026-10-27):
 *
 * master-only seed → Portal Super Admin (bootstrap command) → department, posts,
 * Class Master promotion rule, CGPSC Member → Department Admin by invitation →
 * Department Admin creates the AO and AH → their first login (e-mail OTP, password
 * change) → Pre-DPC (Recruitment Rule generated) → Vacancy record → DPC Proposal →
 * committee → both CSV uploads → Employee Details for every officer, Loyalty, Rule
 * 1.1 → submit → AH verification and approval → CGPSC allotment, schedule,
 * proceeding, calculation, result approval → promotion order.
 *
 * Each step names itself when it fails.
 */
class FreshInstallToPromotionOrderTest extends TestCase
{
    use ReadsSentMail;
    use RefreshDatabase;

    private const FIXTURES = __DIR__.'/../../Fixtures/dpc/';

    private const PASSWORD = 'Dpc@Test2026';

    private int $pdfSerial = 0;

    private function step(string $name, callable $body): mixed
    {
        try {
            return $body();
        } catch (Throwable $e) {
            $this->fail("Step [{$name}] failed: ".$e->getMessage());
        }
    }

    /** @return list<string> */
    private function errors(): array
    {
        $bag = session('errors');

        return match (true) {
            $bag instanceof ViewErrorBag => $bag->getBag('default')->all(),
            is_array($bag) => array_map('strval', Arr::flatten($bag)),
            default => [],
        };
    }

    private function ok(TestResponse $response): TestResponse
    {
        $this->assertSame([], $this->errors(), 'Errors: '.implode(' | ', $this->errors()));
        $this->assertTrue($response->isRedirect() || $response->isOk(), 'HTTP '.$response->status());

        return $response;
    }

    private function pdf(string $name): UploadedFile
    {
        $this->pdfSerial++;

        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% dummy {$this->pdfSerial} {$name}\n%%EOF");
    }

    private function shownPassword(TestResponse $page): string
    {
        preg_match('#id="issued-password">([^<]+)<#', (string) $page->getContent(), $m);
        $this->assertNotEmpty($m, 'password page');

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    /** Sign in with a temporary password: e-mail OTP, then the forced change. */
    private function firstLogin(string $email, string $temporary): User
    {
        auth()->logout();
        $login = $this->post(route('login.store'), ['login' => $email, 'password' => $temporary]);

        // An AO, AH or Department Admin verifies identity (simulated DigiLocker) and
        // gives the mobile declaration first (2026-10-27); a Commission member does not.
        if ($login->headers->get('Location') === route('identity.show')) {
            $this->passIdentityAndDeclaration(User::query()->where('email', $email)->sole())->assertRedirect(route('verify.show'));
        } else {
            $login->assertRedirect(route('verify.show'));
        }

        $this->post(route('verify.store'), ['email_code' => '123456', 'mobile_code' => '123456'])->assertRedirect(route('dashboard'));
        $this->ok($this->post(route('password.change.update'), ['current_password' => $temporary, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]));
        auth()->logout();

        return User::query()->where('email', $email)->sole();
    }

    public function test_a_new_department_runs_its_first_dpc_from_a_fresh_installation(): void
    {
        Storage::fake((string) config('dpc.documents.disk', 'local'));
        Storage::fake((string) config('dpc.imports.disk', 'local'));
        config(['dpc.reservation.category_wise' => false, 'dpc.vacancy_master.source_for_new_proposals' => 'vacancy_master']);

        // Fresh installation: master-only seed; the TestCase Chairman fixture removed.
        CgpscChairman::query()->delete();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(0, User::query()->count());

        $admin = $this->step('0 Portal Super Admin bootstrapped and signed in', function (): User {
            $this->artisan('dpc:bootstrap-super-admin', ['--name' => 'Portal Admin', '--email' => 'superadmin@cgpsc.gov.in', '--mobile' => '9000000001'])->assertSuccessful();
            $user = User::query()->sole();
            $user->forceFill(['email_verified_at' => now(), 'must_change_password' => false])->save();

            return $user;
        });

        $chairman = $this->step('0b Chairman account created and linked to the seeded Chairman record', function () use ($admin): User {
            $page = $this->actingAs($admin)->post(route('admin.users.store'), [
                'name' => 'Dr. Ajay Kumar Shrivastava', 'email' => 'chairman@cgpsc.gov.in', 'mobile' => '9000000002', 'role' => Role::Chairman->value,
            ]);
            $page->assertOk();
            $user = User::query()->where('email', 'chairman@cgpsc.gov.in')->sole();
            $user->forceFill(['email_verified_at' => now(), 'must_change_password' => false])->save();
            CgpscChairman::query()->serving()->sole()->forceFill(['user_id' => $user->id])->save();

            return $user;
        });

        // A department the master does not hold: the seeded ones (PHE among them)
        // already exist, and adding one is the Administrator's own step.
        $department = $this->step('1.1 Admin creates the department', function () use ($admin): Department {
            $this->ok($this->actingAs($admin)->post(route('admin.departments.store'), [
                'code' => 'PHEX', 'name' => 'Public Health Engineering Department (End-to-End)', 'status' => 'unregistered',
                'district_id' => District::query()->where('name_en', 'Raipur')->value('id'),
                'place' => 'Nava Raipur Atal Nagar', 'pin' => '492002', 'email' => 'phex.dpc@cg.gov.in',
            ]));

            return Department::query()->where('code', 'PHEX')->sole();
        });

        $this->step('1.2 designations', function () use ($admin): void {
            foreach (['Assistant Engineer', 'Executive Engineer'] as $name) {
                $this->ok($this->actingAs($admin)->post(route('admin.designations.store'), ['name' => $name, 'status' => 'active']));
            }
        });

        [$from, $to] = $this->step('1.3 posts', function () use ($admin, $department): array {
            foreach ([['Assistant Engineer', 'II', 'L12', 5], ['Executive Engineer', 'I', 'L13', null]] as [$name, $class, $scale, $duration]) {
                $this->ok($this->actingAs($admin)->post(route('admin.department-posts.store'), [
                    'department_id' => $department->id, 'designation_id' => Designation::query()->where('name', $name)->value('id'),
                    'class_level' => $class, 'pay_scale_id' => PayScale::query()->where('code', $scale)->value('id'),
                    'promotion_duration_years' => $duration, 'status' => 'active', 'effective_from' => '2026-01-01',
                ]));
            }

            return [
                DepartmentPost::query()->where('department_id', $department->id)->where('name', 'Assistant Engineer')->sole(),
                DepartmentPost::query()->where('department_id', $department->id)->where('name', 'Executive Engineer')->sole(),
            ];
        });

        $this->step('1.5 Promotion CR rule II -> I 2026', function () use ($admin): void {
            $years = [];
            foreach (range(1, 5) as $before) {
                $years[$before] = ['cr_min_marks' => 5, 'cr_mandatory' => '1', 'loyalty_mandatory' => '0', 'property_mandatory' => '1'];
            }
            $this->ok($this->actingAs($admin)->post(route('admin.service-classes.promotion-rules.store', ClassMaster::query()->where('code', 'I')->value('id')), [
                'from_class' => 'II', 'effective_from_year' => 2026, 'cr_years' => 5, 'loyalty_years' => 0, 'property_years' => 5,
                'years' => $years, 'qualifying_total' => 25, 'super_seat_grade' => 9, 'tribal_bonus' => 0, 'tribal_bonus_skips_extremes' => '1',
            ]));
        });

        $member = $this->step('1.8 CGPSC Member account (password shown on screen)', function () use ($admin): User {
            $page = $this->actingAs($admin)->post(route('admin.users.store'), [
                'name' => 'Shri R. K. Member', 'email' => 'member.test@cgpsc.gov.in', 'mobile' => '9000000301', 'role' => 'member',
            ]);

            return $this->firstLogin('member.test@cgpsc.gov.in', $this->shownPassword($page));
        });

        [$ao, $ah, $deptAdmin, $preDpc] = $this->step('2 Invitation (department + e-mail) -> Department Admin (OTP + DigiLocker) -> opens and fills the Pre-DPC, then allots AO and AH', function () use ($admin, $department, $from, $to): array {
            $designation = fn (string $name): int => (int) Designation::query()->where('name', $name)->value('id');
            // The department and its own e-mail (2026-10-28); both OTPs, the mobile
            // proved with the fixed code as on the local environment.
            config(['dpc.otp.fixed.mobile' => true]);
            $this->ok($this->actingAs($admin)->post(route('admin.department-invitations.store'), [
                'department_id' => $department->id, 'email' => 'phex.dpc@cg.gov.in',
            ]));
            $mail = collect($this->sentMail())->last(fn (array $m): bool => str_contains($m['body'], '/register/'));
            preg_match('#/register/([A-Za-z0-9]{64})#', $mail['body'], $m);
            $token = $m[1];

            // Mobile, both OTPs, DigiLocker, password.
            auth()->logout();
            $this->ok($this->post(route('register.invitation.start', ['token' => $token]), ['mobile' => '9000000401']));
            $this->ok($this->post(route('register.invitation.verify.store', ['token' => $token]), ['email_code' => '123456', 'mobile_code' => '123456']));
            $start = $this->post(route('register.invitation.identity.start', ['token' => $token]));
            parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
            $callback = $this->post(route('identity.simulator.submit'), [
                'state' => (string) $query['state'], 'decision' => 'allow',
                'name' => 'Shri D. Admin', 'date_of_birth' => '1972-08-01', 'gender' => 'M', 'aadhaar_verified' => '1',
            ]);
            $this->get((string) $callback->headers->get('Location'))->assertRedirect(route('register.invitation.password', ['token' => $token]));
            $this->ok($this->post(route('register.invitation.complete', ['token' => $token]), ['password' => 'Dept@Admin2026', 'password_confirmation' => 'Dept@Admin2026']));
            $deptAdmin = User::query()->where('email', 'phex.dpc@cg.gov.in')->sole();
            $this->assertSame('registered', $department->fresh()->status->value);
            $this->assertSame('digilocker_simulated', $deptAdmin->identity_verification_method);

            // The Department Admin's first sign-in: the mobile OTP.
            $this->post(route('login.store'), ['login' => 'phex.dpc@cg.gov.in', 'password' => 'Dept@Admin2026'])->assertRedirect(route('verify.show'));
            $this->passSignInChallenge()->assertRedirect(route('dashboard'));
            auth()->logout();

            // The department's officers, created by the CGPSC Administrator. No
            // DigiLocker for an AO/AH.
            $officers = [];
            foreach ([['ah.phe@cg.gov.in', 'Shri P. K. Head', '9000000302', 'Secretary', Role::AdministrativeHead, 'ah'], ['ao.phe@cg.gov.in', 'Shri S. K. Officer', '9000000303', 'Deputy Secretary', Role::AuthorizedOfficer, 'ao']] as [$email, $name, $mobile, $des, $role, $key]) {
                $page = $this->actingAs($admin)->post(route('admin.users.store'), [
                    'name' => $name, 'designation_id' => $designation($des), 'role' => $role->value, 'department_id' => $department->id,
                    'email' => $email, 'mobile' => $mobile,
                ]);
                $officers[$key] = $this->shownPassword($page);
            }
            $ao = User::query()->where('email', 'ao.phe@cg.gov.in')->sole();
            $ah = User::query()->where('email', 'ah.phe@cg.gov.in')->sole();
            // The Department Admin opens the Pre-DPC, fills all of it in, and
            // assigns its AO and AH as the last step (2026-10-28).
            $this->ok($this->actingAs($deptAdmin->fresh())->post(route('department.allotments.pre-dpc.store'), [
                'dpc_year' => 2026, 'promotion_to_post_id' => $to->id,
            ]));
            $preDpc = PreDpcProposal::query()->sole();
            $this->ok($this->put(route('pre-dpc.update', $preDpc), [
                'dpc_year' => 2026, 'promotion_to_post_id' => $to->id,
                'promotion_sources' => [['promotion_from_post_id' => $from->id, 'promotion_percentage' => 100, 'min_experience_years' => 5,
                    'experience_unit' => 'years', 'qualification_ids' => [Qualification::query()->where('name_en', 'B.E.')->value('id')]]],
                'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
                'government_order_number' => 'F-1/2026/PHE/RR', 'effective_from' => '2026-01-01',
            ]));
            $this->ok($this->put(route('department.allotments.pre-dpc.update', $preDpc), ['ao_user_id' => $ao->id, 'ah_user_id' => $ah->id]));
            $this->assertSame([$ao->id, $ah->id], [$preDpc->fresh()->currentAllotment->ao_user_id, $preDpc->fresh()->currentAllotment->ah_user_id]);

            return [$this->firstLogin('ao.phe@cg.gov.in', $officers['ao']), $this->firstLogin('ah.phe@cg.gov.in', $officers['ah']), $deptAdmin, $preDpc];
        });

        $preDpc = $this->step('3 AO reviews and submits the Pre-DPC with eSign, AH approves with eSign; Recruitment Rule created', function () use ($ao, $ah, $preDpc, $to): PreDpcProposal {
            $this->actingAs($ao)->get(route('pre-dpc.edit', $preDpc))->assertOk();
            $this->ok($this->actingAs($ao)->post(route('pre-dpc.submit', $preDpc)));
            $this->ok($this->actingAs($ah)->post(route('pre-dpc.approve', $preDpc)));
            $this->assertSame('approved', $preDpc->fresh()->status->value);
            $this->assertSame(100.0, (float) RecruitmentRule::query()->where('post_id', $to->id)->sole()->promotion_recruitment_pct);

            return $preDpc->fresh();
        });

        $this->step('4 Vacancy record 10/7 finalized', function () use ($ao, $department, $to): void {
            $params = [$department, 2026, $to];
            $this->ok($this->actingAs($ao)->post(route('vacancy-records.open', $params)));
            $this->ok($this->actingAs($ao)->post(route('vacancy-records.sanctioned-strength', $params), [
                'sanctioned_total' => 10, 'order_number' => 'PHE/SANC/2026', 'order_date' => '2026-01-01', 'document' => $this->pdf('s.pdf'),
            ]));
            $this->ok($this->actingAs($ao)->post(route('vacancy-records.working-strength', $params), [
                'working_total' => 7, 'working_promotion' => 7, 'working_direct' => 0, 'working_deputation' => 0,
                'order_number' => 'PHE/EST/2026', 'order_date' => '2026-01-01', 'document' => $this->pdf('w.pdf'),
            ]));
            $this->ok($this->actingAs($ao)->post(route('vacancy-records.finalize', $params)));
            $this->assertSame(3, (int) VacancyPosition::query()->sole()->promotion_vacancy);
        });

        $proposal = $this->step('5.1 DPC Proposal', function () use ($ao, $preDpc): DpcProposal {
            $this->ok($this->actingAs($ao)->post(route('dpc-proposals.store'), [
                'pre_dpc_proposal_id' => $preDpc->id, 'dpc_type_id' => DpcType::query()->where('code', 'fresh')->value('id'), 'legal_stay' => '0',
            ]));

            return DpcProposal::query()->sole();
        });

        $this->step('5.2 Committee', function () use ($ao, $proposal): void {
            $designation = fn (string $name): int => (int) Designation::query()->where('name', $name)->value('id');
            $this->ok($this->actingAs($ao)->put(route('dpc-proposals.committee.update', $proposal), ['members' => [
                ['name' => 'Shri R. K. Member', 'email' => 'member.test@cgpsc.gov.in', 'mobile' => '9000000301', 'designation_id' => $designation('Member, CGPSC'), 'capacity' => 'member', 'is_sc' => '1'],
                ['name' => 'Smt. A. Test', 'mobile' => '9000000311', 'designation_id' => $designation('Joint Secretary'), 'capacity' => 'member', 'is_st' => '1', 'is_female' => '1'],
                ['name' => 'Shri B. Test', 'mobile' => '9000000312', 'designation_id' => $designation('Deputy Secretary'), 'capacity' => 'member', 'is_obc' => '1', 'is_bhar_sadhak_sachiv' => '1'],
                ['name' => 'Shri C. Test', 'mobile' => '9000000313', 'designation_id' => $designation('Under Secretary'), 'capacity' => 'member', 'is_vibhag_sachiv' => '1'],
            ]]));
        });

        $this->step('6 CSV uploads', function () use ($ao, $proposal): void {
            foreach ([['currently_working_us', 'working_officers_7_working_3_vacant.csv', []], ['final_seniority_list_us', 'seniority_list_10_officers.csv', ['seniority_list_as_on' => '2025-04-01']]] as [$template, $name, $extra]) {
                $copy = tempnam(sys_get_temp_dir(), 'e2e').'.csv';
                copy(self::FIXTURES.$name, $copy);
                $this->ok($this->actingAs($ao)->post(route('dpc-proposals.imports.store', $proposal), ['template' => $template, 'file' => new UploadedFile($copy, $name, 'text/csv', null, true)] + $extra));
                $batch = ImportBatch::query()->latest('id')->firstOrFail();
                $this->assertSame(0, (int) $batch->rejected_count);
                $this->ok($this->actingAs($ao)->post(route('dpc-proposals.imports.commit', [$proposal, $batch])));
            }
        });

        $this->step('7 Employee Details x10 + Loyalty + Rule 1.1', function () use ($ao, $proposal): void {
            $route = fn (string $n, DpcEvaluationRow $r): string => route('dpc-proposals.employees.'.$n, [$proposal, $r]);
            foreach ($proposal->evaluationRows()->orderBy('sn')->get() as $row) {
                $this->ok($this->actingAs($ao)->put($route('basic', $row), ['dob_order_number' => 'SR/'.$row->sn, 'dob_order_date' => '1997-03-03', 'joining_order_number' => 'J/'.$row->sn, 'joining_order_date' => '1997-03-03', 'service_record_undertaking' => '1']));
                foreach ([2021, 2022, 2023, 2024, 2025] as $year) {
                    $this->ok($this->actingAs($ao)->post($route('cr.save', $row), ['year' => $year, 'put_up' => '1', 'has_grading' => '1', 'reports' => [[
                        'reporter_designation' => 'SE', 'reporter_grade' => 'क', 'reviewer_designation' => 'CE', 'reviewer_grade' => 'क', 'accepting_designation' => 'Secy', 'accepting_grade' => 'क']]]));
                    $this->ok($this->actingAs($ao)->post($route('documents.store', $row), ['verified' => '1', 'kind' => 'cr_report', 'year' => $year, 'undertaking' => '1', 'document' => $this->pdf("cr{$row->sn}{$year}.pdf")]));
                    $this->ok($this->actingAs($ao)->post($route('property.save', $row), ['year' => $year, 'submitted' => '1', 'submitted_on' => ($year + 1).'-01-15', 'next_increment_date' => ($year + 1).'-07-01']));
                    $this->ok($this->actingAs($ao)->post($route('documents.store', $row), ['verified' => '1', 'kind' => 'property_return', 'year' => $year, 'undertaking' => '1', 'document' => $this->pdf("ipr{$row->sn}{$year}.pdf")]));
                }
                $this->ok($this->actingAs($ao)->post($route('documents.store', $row), ['verified' => '1', 'kind' => 'cr_channel', 'undertaking' => '1', 'document' => $this->pdf("ch{$row->sn}.pdf")]));
                $this->ok($this->actingAs($ao)->put($route('vigilance', $row), ['vigilance_pending' => '0']));
                $this->ok($this->actingAs($ao)->put($route('ph', $row), ['is_ph' => '0']));
                $this->ok($this->actingAs($ao)->put($route('declarations', $row), ['unsanctioned_deputation' => '0', 'refused_promotion' => '0']));
                $this->assertSame([], array_filter(app(EmployeeDetailsService::class)->status($row->fresh())['sections']));
            }

            $rows = [];
            foreach ($proposal->evaluationRows()->with(['crGrades', 'propertyFlags'])->orderBy('sn')->get() as $row) {
                $rows[] = ['sn' => $row->sn,
                    'cr_grades' => $row->crGrades->map(fn ($c): array => ['year' => $c->year, 'grade' => $c->grade])->values()->all(),
                    'property_flags' => $row->propertyFlags->map(fn ($p): array => ['year' => $p->year, 'flag' => $p->flag ? '1' : '0'])->values()->all(),
                    'loyalty_flags' => array_map(fn (int $y): array => ['year' => $y, 'flag' => '1'], [2021, 2022, 2023, 2024, 2025])];
            }
            $this->ok($this->actingAs($ao)->put(route('dpc-proposals.evaluation-chart.update', $proposal), ['rows' => $rows]));

            foreach (['sanctioned_strength', 'recruitment_rule', 'seniority_list', 'original_documents', 'representations'] as $item) {
                $this->ok($this->actingAs($ao)->post(route('dpc-proposals.verification.store', [$proposal, $item]), ['confirm' => '1']));
            }
            $this->ok($this->actingAs($ao)->post(route('dpc-proposals.verification.store', [$proposal, 'previous_dpc_reconciliation']), ['confirm' => '1', 'previous_dpc_basis' => 'none', 'remarks' => 'No previous DPC for this post.']));
            $this->ok($this->actingAs($ao)->post(route('dpc-proposals.verification.store', [$proposal, 'recruitment_rule_conformity']), ['confirm' => '1', 'rr_notified_on' => '2026-01-01']));
            $this->assertSame([], app(DpcProposalCompletenessService::class)->missingSections($proposal->fresh()));
        });

        $this->step('8 Submit; AH verifies and approves', function () use ($ao, $ah, $proposal): void {
            $this->ok($this->actingAs($ao)->post(route('dpc-proposals.submit', $proposal)));
            foreach ($proposal->evaluationRows()->get() as $row) {
                $this->ok($this->actingAs($ah)->post(route('dpc-proposals.employees.documents.verify-all', [$proposal, $row])));
            }
            $this->ok($this->actingAs($ah)->post(route('dpc-proposals.approve', $proposal)));
            $this->assertSame('approved', $proposal->fresh()->status->value);
        });

        $this->step('9 CGPSC: allot, accept, schedule, open, calculate, approve', function () use ($chairman, $member, $proposal): void {
            $seat = DpcCommitteeMember::query()->where('dpc_proposal_id', $proposal->id)->where('user_id', $member->id)->sole();
            $this->ok($this->actingAs($chairman)->post(route('cgpsc.allotments.store', $proposal), ['allotted_to' => $member->id, 'dpc_chairman_member_id' => $seat->id, 'complete_by' => now()->addDays(30)->toDateString()]));
            $this->ok($this->actingAs($member)->post(route('cgpsc.schedules.accept', $proposal)));
            $this->ok($this->actingAs($member)->post(route('cgpsc.schedules.store', $proposal), ['dpc_at' => now()->addDays(2)->format('Y-m-d\TH:i')]));
            $this->ok($this->actingAs($member)->post(route('cgpsc.proceedings.open', $proposal)));
            $proceeding = DpcProceeding::query()->sole();
            $this->ok($this->actingAs($member)->post(route('cgpsc.proceedings.calculate', $proceeding)));
            $this->ok($this->actingAs($member)->post(route('cgpsc.proceedings.approve', $proceeding), ['promotion_as_on_year' => 2026]));
            $this->assertSame('result_approved', $proceeding->fresh()->status->value);
        });

        $this->step('10 AH issues the promotion order', function () use ($ah, $proposal): void {
            $this->ok($this->actingAs($ah)->post(route('dpc-proposals.promotion-orders.store', $proposal), ['order_number' => 'F-2/2026/PHE/PROMO', 'order_date' => now()->toDateString(), 'confirm' => '1']));
            $order = DpcPromotionOrder::query()->sole();
            $this->assertStringStartsWith('%PDF', (string) Storage::disk((string) $order->disk)->get((string) $order->path));
            $this->assertCount(3, (array) $order->officers, 'Three promotion vacancies: three officers promoted.');
        });
    }
}
