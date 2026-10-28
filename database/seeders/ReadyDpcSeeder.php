<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Events\CredentialsIssued;
use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\Services\VacancyMasterService;
use App\Domain\Master\Models\ClassMaster;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\Designation;
use App\Domain\Master\Models\District;
use App\Domain\Master\Models\PromotionCrRule;
use App\Domain\Master\Models\Qualification;
use App\Domain\Master\Services\PromotionCrRules;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\PreDpc\Services\PreDpcProposalService;
use App\Domain\UserManagement\Services\UserProvisioningService;
use App\Enums\DepartmentStatus;
use App\Enums\Role;
use Database\Seeders\Demo\DemoActor;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Everything one department needs to start one DPC (2026-10-19): School Education
 * registered with its Authorized Officer and Administrative Head, the Pre-DPC for
 * Block Education Officer → Additional Director, Education approved (which writes
 * the Recruitment Rule), the 2026 vacancy record finalized, and the Class II → Class I
 * promotion rule. From here the AO raises the DPC proposal.
 *
 * Each step goes through the service the screens use, acting as the officer who
 * would take it, and is skipped when already there, so the seeder may run again.
 *
 * The two accounts sign in with `dpc.ready_department.password` and are verified.
 * Never in production: the Pre-DPC approval there needs a real digital signature,
 * and the accounts would carry a known password.
 */
class ReadyDpcSeeder extends Seeder
{
    public const YEAR = 2026;

    public const GOVERNMENT_ORDER = 'F-2-14/2025/20-Three';

    public const EFFECTIVE_FROM = '2026-01-01';

    public const FROM_POST = 'Block Education Officer';

    public const TO_POST = 'Additional Director, Education';

    public const AO_EMAIL = 'ao.schooleducation@cg.gov.in';

    public const AH_EMAIL = 'ah.schooleducation@cg.gov.in';

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        // Workflow notifications must not depend on a queue worker (or Redis) being up.
        config(['queue.default' => 'sync']);

        $department = Department::query()->where('code', 'SE')->first();
        $from = DepartmentPost::query()->where('department_id', $department?->id)->where('name', self::FROM_POST)->first();
        $to = DepartmentPost::query()->where('department_id', $department?->id)->where('name', self::TO_POST)->first();

        if ($department === null || $from === null || $to === null) {
            return;
        }

        [$ao, $ah] = $this->officers($department);

        $this->approvedPreDpc($department, $from, $to, $ao, $ah);
        $this->finalizedVacancy($department, $to, $ao);
        $this->promotionRule();
    }

    /**
     * Registration through the department's own form, then a known password and both
     * channels verified so the officers sign straight in.
     *
     * @return array{0: User, 1: User}
     */
    private function officers(Department $department): array
    {
        if (! $department->users()->exists()) {
            $designation = fn (string $name): int => (int) Designation::query()->where('name', $name)->value('id');

            // The credentials notice would mail a random password this seeder replaces.
            // The address is the Administrator's master data; registration reads it.
            $department->forceFill([
                'district_id' => (int) District::query()->where('name_en', 'Raipur')->value('id'),
                'place' => $department->place ?? 'Mahanadi Bhawan, Nava Raipur Atal Nagar',
                'pin' => $department->pin ?? '492002',
                'email' => $department->email ?? 'schooleducation@cg.gov.in',
            ])->save();

            // Created as the Department Admin or Portal Admin would create them; the
            // credentials notice is suppressed because this seeder sets the password.
            Event::fakeFor(function () use ($department, $designation): void {
                $provisioning = app(UserProvisioningService::class);

                foreach ([
                    [Role::AdministrativeHead, 'Smt. Sunita Rathore', 'Secretary, School Education', '9000001102', self::AH_EMAIL],
                    [Role::AuthorizedOfficer, 'Shri Anil Kumar Verma', 'Under Secretary', '9000001101', self::AO_EMAIL],
                ] as [$role, $name, $designationName, $mobile, $email]) {
                    $provisioning->provision([
                        'name' => $name, 'email' => $email, 'mobile' => $mobile,
                        'designation_id' => $designation($designationName),
                        'role' => $role->value, 'department_id' => $department->id,
                        // Demonstration identity (2026-10-27): verified through the
                        // simulated DigiLocker at first sign-in, like any officer.
                        'date_of_birth' => '1976-03-15', 'gender' => str_starts_with($name, 'Smt') ? 'F' : 'M',
                    ]);
                }

                if ($department->status === DepartmentStatus::Unregistered) {
                    $department->forceFill(['status' => DepartmentStatus::Registered->value, 'registered_at' => now()])->save();
                }
            }, [CredentialsIssued::class]);
        }

        $ao = User::query()->where('email', self::AO_EMAIL)->firstOrFail();
        $ah = User::query()->where('email', self::AH_EMAIL)->firstOrFail();

        $password = $this->demonstrationPassword();

        foreach ([$ao, $ah] as $user) {
            $user->forceFill([
                'password' => $password,
                'must_change_password' => false,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'mobile_verified_at' => $user->mobile_verified_at ?? now(),
                'is_active' => true,
            ])->save();
        }

        return [$ao, $ah];
    }

    /**
     * Drafted and submitted by the AO, approved by the AH: the approval signs the
     * order and writes the post's Recruitment Rule (100 % promotion, from 1 January).
     */
    private function approvedPreDpc(Department $department, DepartmentPost $from, DepartmentPost $to, User $ao, User $ah): void
    {
        if (PreDpcProposal::query()->where('department_id', $department->id)->where('promotion_to_post_id', $to->id)->exists()) {
            return;
        }

        $service = app(PreDpcProposalService::class);

        DemoActor::as($ao, function () use ($service, $department, $from, $to, $ao, $ah): void {
            $proposal = $service->createDraft($department, $ao, [
                'dpc_year' => self::YEAR,
                'promotion_to_post_id' => $to->id,
                'direct_recruitment_pct' => 0,
                'promotion_recruitment_pct' => 100,
                'government_order_number' => self::GOVERNMENT_ORDER,
                'effective_from' => self::EFFECTIVE_FROM,
                'promotion_sources' => [[
                    'promotion_from_post_id' => $from->id,
                    'promotion_percentage' => 100,
                    'min_experience_years' => 3,
                    'experience_unit' => 'years',
                    'qualification_ids' => Qualification::query()->where('name_en', 'Post-Graduate')->pluck('id')->all(),
                ]],
            ]);

            // Allotted to this AO and AH, as the Department Admin would (2026-10-26):
            // the DPC the AO raises from it inherits the allotment.
            app(ProposalAllotmentService::class)->allot($proposal, $ao, $ah, null);

            $service->submit($proposal, $ao, DemoActor::request());

            DemoActor::as($ah, fn () => $service->approve($proposal->refresh(), $ah, DemoActor::request()));
        });
    }

    /**
     * The AO's Vacancy Management record for 2026: sanctioned 10, working 7 (all on
     * the promotion quota) — a promotion vacancy of 3 — finalized.
     */
    private function finalizedVacancy(Department $department, DepartmentPost $to, User $ao): void
    {
        if (VacancyPosition::query()->where('department_id', $department->id)->where('post_name', $to->name)->where('vacancy_year', self::YEAR)->exists()) {
            return;
        }

        $service = app(VacancyMasterService::class);

        DemoActor::as($ao, function () use ($service, $department, $to, $ao): void {
            $position = $service->openDraft($department, $to, self::YEAR, $ao);

            $service->saveSanctionedStrength($position, [
                'sanctioned_total' => 10,
                'order_number' => self::GOVERNMENT_ORDER,
                'order_date' => self::EFFECTIVE_FROM,
            ], $this->order('sanctioned-strength-order.pdf'), $ao);

            $service->saveWorkingStrength($position, [
                'working_total' => 7,
                'working_promotion' => 7,
                'working_direct' => 0,
                'working_deputation' => 0,
                'order_number' => self::GOVERNMENT_ORDER,
                'order_date' => self::EFFECTIVE_FROM,
            ], $this->order('working-strength-order.pdf'), $ao);

            $service->finalize($position->refresh(), $ao);
        });
    }

    /**
     * Class II → Class I from DPC year 2026: the last five years' CR, Property Return
     * and Loyalty, each year mandatory, and a total of at least 25 points (ख, 5, a year).
     *
     * The Coder Instructions leave these figures to master data (rule 3.5); they are a
     * starting point the Administrator corrects on Class Master → Promotion rules.
     */
    private function promotionRule(): void
    {
        $to = ClassMaster::query()->where('code', 'I')->first();
        $admin = User::role(Role::Admin->value)->orderBy('id')->first();

        if ($to === null || $admin === null
            || PromotionCrRule::query()->where('to_class', 'I')->where('from_class', 'II')->exists()) {
            return;
        }

        $years = [];

        foreach (range(1, 5) as $before) {
            $years[$before] = ['cr_min_marks' => '', 'cr_mandatory' => true, 'loyalty_mandatory' => true, 'property_mandatory' => true];
        }

        app(PromotionCrRules::class)->record($to, [
            'from_class' => 'II',
            'effective_from_year' => self::YEAR,
            'cr_years' => 5,
            'loyalty_years' => 5,
            'property_years' => 5,
            'qualifying_total' => '25',
            'super_seat_grade' => '9',
            'tribal_bonus' => '0',
            'remarks' => 'Seeded starting point; figures to be confirmed by the Administrator.',
            'years' => $years,
        ], $admin);
    }

    /**
     * A one-page PDF standing in for the Government Order the record cites.
     */
    private function order(string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'dpc-order');
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    /**
     * The officers' password on a demonstration machine: `DPC_DEPARTMENT_PASSWORD`
     * when set, else a random one printed once (2026-10-26: no shared default).
     */
    private function demonstrationPassword(): string
    {
        $configured = (string) config('dpc.ready_department.password', '');

        if ($configured !== '') {
            return $configured;
        }

        static $generated = null;
        $generated ??= Str::password(16);
        $this->command->warn('ReadyDpcSeeder: the AO and AH password is '.$generated.' (shown once; set DPC_DEPARTMENT_PASSWORD to choose it).');

        return $generated;
    }
}
