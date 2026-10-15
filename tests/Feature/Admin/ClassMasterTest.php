<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\ClassMaster;
use App\Enums\Role;
use App\Enums\ServiceClass;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Change item 1: the Class Master is one of the masters the single CGPSC
 * Administrator owns.
 *
 * The division of authority is the interesting part and most of what is asserted
 * here: the *names* are the Administrator's, the *codes* are the service rules'
 * — because the M5 engine keys its zone-of-consideration rules on those codes and
 * one it has no rule for would silently fall through to the uncapped default.
 */
class ClassMasterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);
    }

    public function test_the_migration_seeds_every_statutory_class(): void
    {
        $this->assertSame(count(ServiceClass::cases()), ClassMaster::query()->count());

        foreach (ServiceClass::cases() as $class) {
            $this->assertDatabaseHas('service_classes', ['code' => $class->value]);
        }
    }

    public function test_the_administrator_can_reach_the_class_master(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.service-classes.index'))
            ->assertOk()
            ->assertSee('Class Master')
            ->assertSee('Class-I');
    }

    public function test_renaming_a_class_changes_what_every_screen_shows(): void
    {
        $classOne = ClassMaster::query()->where('code', 'I')->sole();

        $this->actingAs($this->admin)
            ->put(route('admin.service-classes.update', $classOne), [
                'code' => 'I',
                'name_en' => 'Class-I (Gazetted)',
                'name_hi' => 'वर्ग-1 (राजपत्रित)',
                'sort_order' => 10,
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.service-classes.index'));

        // The enum reads its label from the master, so the rename reaches the
        // thirty-odd view sites that call `label()` without any of them changing.
        $this->assertSame('Class-I (Gazetted)', ServiceClass::I->label());
    }

    public function test_the_hindi_name_is_used_when_the_locale_is_hindi(): void
    {
        $classOne = ClassMaster::query()->where('code', 'I')->sole();
        $classOne->forceFill(['name_hi' => 'वर्ग-1'])->save();

        app()->setLocale('hi');
        $this->assertSame('वर्ग-1', ServiceClass::I->label());

        app()->setLocale('en');
        $this->assertSame('Class-I', ServiceClass::I->label());
    }

    public function test_a_class_with_no_hindi_name_falls_back_to_english(): void
    {
        $classOne = ClassMaster::query()->where('code', 'I')->sole();
        $classOne->forceFill(['name_hi' => null])->save();

        app()->setLocale('hi');

        $this->assertSame('Class-I', ServiceClass::I->label());
    }

    public function test_a_class_code_the_engine_has_no_rule_for_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.service-classes.store'), [
                'code' => 'IV',
                'name_en' => 'Class-IV',
                'sort_order' => 40,
                'status' => 'active',
            ])
            ->assertSessionHasErrors('code');

        $this->assertDatabaseMissing('service_classes', ['code' => 'IV']);
    }

    public function test_a_duplicate_class_code_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.service-classes.store'), [
                'code' => 'I',
                'name_en' => 'Class-I again',
                'sort_order' => 10,
                'status' => 'active',
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_a_withdrawn_class_still_labels_the_posts_that_cite_it(): void
    {
        $classThree = ClassMaster::query()->where('code', 'III')->sole();

        $this->actingAs($this->admin)
            ->delete(route('admin.service-classes.destroy', $classThree))
            ->assertRedirect(route('admin.service-classes.index'));

        $this->assertSoftDeleted('service_classes', ['code' => 'III']);

        // The record of what class a post was does not disappear because the
        // class was later retired.
        $this->assertSame('Class-III', ServiceClass::III->label());
    }

    public function test_a_label_still_resolves_when_the_master_holds_nothing(): void
    {
        ClassMaster::query()->forceDelete();
        ClassMaster::flushCache();

        // A migrated-but-unseeded database, and every unit test that never
        // touches the master. A missing *label* is safe to derive; a missing
        // business value never is.
        $this->assertSame('Class-II', ServiceClass::II->label());
    }

    public function test_a_non_administrator_cannot_reach_the_class_master(): void
    {
        $member = User::factory()->create();
        $member->assignRole(Role::Member->value);

        $this->actingAs($member)->get(route('admin.service-classes.index'))->assertForbidden();
    }
}
