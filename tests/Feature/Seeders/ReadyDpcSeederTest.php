<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\VacancyPositionStatus;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Models\VacancyPosition;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\PromotionCrRule;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Enums\DepartmentStatus;
use App\Enums\Role;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReadyDpcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A fresh database — migrations, then `db:seed` — is ready for School Education's
 * AO to raise the DPC for Additional Director, Education (2026-10-19).
 */
class ReadyDpcSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('dpc.documents.disk'));
        Storage::fake((string) config('dpc.signature.disk', 'local'));
        config(['dpc.ready_department.password' => 'Ready@2026']);

        $this->seed(DatabaseSeeder::class);
    }

    public function test_one_department_is_registered_with_its_ao_and_ah_only(): void
    {
        $this->assertSame(['SE'], Department::query()->pluck('code')->all());
        $department = Department::query()->sole();
        $this->assertSame(DepartmentStatus::Registered, $department->status);

        $ao = User::query()->where('email', ReadyDpcSeeder::AO_EMAIL)->sole();
        $ah = User::query()->where('email', ReadyDpcSeeder::AH_EMAIL)->sole();
        $this->assertTrue($ao->hasRole(Role::AuthorizedOfficer->value));
        $this->assertTrue($ah->hasRole(Role::AdministrativeHead->value));
        $this->assertSame($department->id, $ao->department_id);
        $this->assertSame($department->id, $ah->department_id);
        $this->assertSame(2, $department->users()->count());

        // Admin, Chairman, AO, AH — nobody else.
        $this->assertSame(4, User::query()->count());

        $this->post(route('login.store'), ['login' => $ao->email, 'password' => 'Ready@2026'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($ao);
    }

    public function test_the_pre_dpc_rule_vacancy_and_promotion_rule_are_ready(): void
    {
        $preDpc = PreDpcProposal::query()->activeApproved()->sole();
        $this->assertSame(ReadyDpcSeeder::YEAR, $preDpc->dpc_year);
        $this->assertSame(3, $preDpc->promotionSources()->sole()->promotion_duration_years);

        $rule = RecruitmentRule::query()->where('status', 'active')->sole();
        $this->assertSame(100, (int) $rule->promotion_recruitment_pct);

        $position = VacancyPosition::query()->sole();
        $this->assertSame(VacancyPositionStatus::Finalized, $position->status);
        $this->assertSame([10, 3], [(int) $position->sanctioned_strength, (int) $position->promotion_vacancy]);

        $this->assertSame(1, PromotionCrRule::query()->where('to_class', 'I')->where('from_class', 'II')->count());
    }

    public function test_the_ao_raises_the_dpc_and_reconciles_the_working_officers_list(): void
    {
        config(['dpc.reservation.category_wise' => false, 'dpc.vacancy_master.source_for_new_proposals' => 'vacancy_master']);
        $ao = User::query()->where('email', ReadyDpcSeeder::AO_EMAIL)->sole();
        $preDpc = PreDpcProposal::query()->activeApproved()->sole();

        $this->actingAs($ao)->post(route('dpc-proposals.store'), [
            'pre_dpc_proposal_id' => $preDpc->id,
            'promotion_from_post_id' => $preDpc->promotionSources()->sole()->promotion_from_post_id,
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->sole()->id,
            'legal_stay' => '0',
        ])->assertSessionHasNoErrors();

        $proposal = DpcProposal::query()->sole();
        $this->assertSame('vacancy_master', $proposal->vacancy_source);

        // The record is ready; step 1 waits for the AO's Working Officers List.
        $section = fn (): array => collect(app(DpcProposalCompletenessService::class)->checklist($proposal->refresh()))->firstWhere('key', 'vacancy_master');
        $this->assertSame(__('vacancymaster.proposal.working_list.section_missing'), $section()['detail']);

        // The seeded record: 7 working, all on the promotion quota.
        $category = ReservationCategory::query()->where('code', 'UR')->sole();
        app(DpcProposalService::class)->saveWorkingOfficers($proposal, array_map(fn (int $sn): array => [
            'sn' => $sn, 'status' => 'working', 'source' => 'promotion', 'name' => 'Officer '.$sn, 'reservation_category_id' => $category->id,
        ], range(1, 7)), $ao);

        $this->assertTrue($section()['complete'], (string) $section()['detail']);
    }

    public function test_seeding_again_changes_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Department::query()->count());
        $this->assertSame(DepartmentStatus::Registered, Department::query()->sole()->status);
        $this->assertSame(4, User::query()->count());
        $this->assertSame(1, PreDpcProposal::query()->count());
        $this->assertSame(1, VacancyPosition::query()->count());
        $this->assertSame(1, PromotionCrRule::query()->count());
    }
}
