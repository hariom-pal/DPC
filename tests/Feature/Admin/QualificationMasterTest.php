<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\Qualification;
use App\Domain\Master\Models\RecruitmentRule;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\DepartmentMasterSeeder;
use Database\Seeders\DepartmentPostSeeder;
use Database\Seeders\QualificationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Qualification master, and the two guards that make it safe to expose: a
 * qualification rules already cite is withdrawn rather than deleted, and only an
 * Active one may be attached to a new rule.
 */
class QualificationMasterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentMasterSeeder::class, DepartmentPostSeeder::class]);
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        return $admin;
    }

    public function test_admin_can_create_a_qualification(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.qualifications.store'), [
                'code' => 'MPHIL',
                'name_en' => 'M.Phil.',
                'name_hi' => 'एम.फिल.',
                'description' => 'Master of Philosophy.',
                'status' => MasterStatus::Active->value,
            ])
            ->assertRedirect(route('admin.qualifications.index'));

        $this->assertDatabaseHas('qualifications', ['code' => 'MPHIL', 'name_en' => 'M.Phil.']);
    }

    public function test_the_seeded_catalogue_covers_the_qualifications_in_use(): void
    {
        $this->seed(QualificationSeeder::class);

        // The three the seeded Schedule-IV rules actually require, plus the
        // examples the master was asked to ship with.
        foreach ([
            'Post-Graduate', 'Post-Graduate with B.Ed.', 'B.Ed.', 'M.Ed.',
            'B.E.', 'B.Tech.', 'LL.B.', 'M.B.B.S.', 'M.Sc.', 'Ph.D.',
        ] as $name) {
            $this->assertDatabaseHas('qualifications', ['name_en' => $name, 'status' => MasterStatus::Active->value]);
        }
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(QualificationSeeder::class);
        $first = Qualification::query()->count();

        $this->seed(QualificationSeeder::class);

        $this->assertSame($first, Qualification::query()->count());
    }

    public function test_a_duplicate_display_name_is_refused(): void
    {
        $admin = $this->admin();
        $this->seed(QualificationSeeder::class);

        $this->actingAs($admin)
            ->post(route('admin.qualifications.store'), [
                'code' => 'B_ED_2',
                'name_en' => 'B.Ed.',
                'name_hi' => 'बी.एड.',
                'status' => MasterStatus::Active->value,
            ])
            ->assertSessionHasErrors('name_en');
    }

    public function test_a_qualification_in_use_is_not_deleted(): void
    {
        $admin = $this->admin();
        $qualification = Qualification::query()->where('code', 'GRAD')->firstOrFail();

        $target = DepartmentPost::query()->where('name', 'Deputy Secretary')->firstOrFail();
        $feeder = DepartmentPost::query()->where('name', 'Under Secretary')->firstOrFail();

        $rule = RecruitmentRule::query()->create([
            'post_id' => $target->id, 'version' => 1, 'government_order_number' => 'GO/1',
            'direct_recruitment_pct' => 0, 'promotion_recruitment_pct' => 100,
            'effective_from' => '2020-01-01', 'status' => MasterStatus::Active->value,
        ]);
        $source = $rule->promotionSources()->create([
            'promotion_from_post_id' => $feeder->id, 'promotion_percentage' => 100,
            'min_experience_years' => 5,
        ]);

        // A pivot since item 11 (2026-08-26), so "in use" is a join rather than a
        // column on the source.
        $source->qualifications()->attach($qualification->id, ['sort_order' => 0]);

        $this->actingAs($admin)
            ->delete(route('admin.qualifications.destroy', $qualification))
            ->assertRedirect(route('admin.qualifications.index'));

        $this->assertNotSoftDeleted($qualification);
    }

    public function test_an_unused_qualification_can_be_deleted(): void
    {
        $admin = $this->admin();
        $qualification = Qualification::query()->create([
            'code' => 'UNUSED', 'name_en' => 'Unused Degree', 'name_hi' => 'अप्रयुक्त',
            'status' => MasterStatus::Active->value,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.qualifications.destroy', $qualification))
            ->assertRedirect(route('admin.qualifications.index'));

        $this->assertSoftDeleted($qualification);
    }

    /**
     * One row per lifecycle status, plus one soft-deleted Active row — only the
     * plain Active one may be offered on a new rule.
     */
    public function test_only_active_qualifications_are_selectable(): void
    {
        foreach (MasterStatus::cases() as $status) {
            Qualification::query()->create([
                'code' => 'SCOPE_'.$status->value, 'name_en' => 'Scope '.$status->value,
                'name_hi' => 'क्यू', 'status' => $status->value,
            ]);
        }

        Qualification::query()->create([
            'code' => 'SCOPE_trashed', 'name_en' => 'Scope trashed',
            'name_hi' => 'क्यू', 'status' => MasterStatus::Active->value,
        ])->delete();

        $selectable = Qualification::query()->selectable()
            ->where('code', 'like', 'SCOPE_%')->pluck('name_en');

        $this->assertSame(['Scope active'], $selectable->all());
    }
}
