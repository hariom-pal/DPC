<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Support\MasterRegistry;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * The DPC Types CSV template named the Fresh type `FRESH`; the seeded code is
 * `fresh`, and the Fresh DPC screen compares against `fresh`.
 *
 * On MySQL the uppercase sample matched the seeded row (case-insensitive collation)
 * and the import rewrote its code to `FRESH`, after which no Fresh DPC could be
 * raised. On SQLite the same file created a second "Fresh DPC" row instead.
 */
class DpcTypeTemplateCodeTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);
    }

    public function test_the_template_names_the_seeded_code(): void
    {
        $sample = MasterRegistry::all()['dpc-types']->sample;

        $this->assertSame('fresh', $sample[0]['code']);
        $this->assertTrue(DpcType::query()->where('code', $sample[0]['code'])->exists());
    }

    public function test_the_unaltered_template_updates_the_seeded_type_without_rewriting_its_code(): void
    {
        $before = DpcType::query()->count();

        $csv = $this->actingAs($this->admin)
            ->get(route('admin.master-data.template', 'dpc-types'))
            ->streamedContent();

        $this->actingAs($this->admin)
            ->post(route('admin.master-data.import', 'dpc-types'), [
                'file' => UploadedFile::fake()->createWithContent('dpc-types.csv', $csv),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($before, DpcType::query()->count(), 'The template must not add a second Fresh type.');
        $this->assertSame(1, DpcType::query()->where('code', 'fresh')->count());
        $this->assertSame(0, DpcType::query()->where('code', 'FRESH')->count());
    }

    public function test_an_uppercase_code_in_an_upload_matches_the_seeded_type_and_keeps_its_spelling(): void
    {
        $before = DpcType::query()->count();

        $this->actingAs($this->admin)
            ->post(route('admin.master-data.import', 'dpc-types'), [
                'file' => UploadedFile::fake()->createWithContent(
                    'dpc-types.csv',
                    "code,name_en,name_hi,status\nFRESH,Fresh DPC (renamed),नवीन,active\n",
                ),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($before, DpcType::query()->count());

        $fresh = DpcType::query()->where('code', 'fresh')->sole();
        $this->assertSame('Fresh DPC (renamed)', $fresh->name_en, 'The matching row is updated.');
    }

    public function test_a_fresh_dpc_can_still_be_raised_after_the_template_is_imported(): void
    {
        $csv = $this->actingAs($this->admin)
            ->get(route('admin.master-data.template', 'dpc-types'))
            ->streamedContent();

        $this->actingAs($this->admin)->post(route('admin.master-data.import', 'dpc-types'), [
            'file' => UploadedFile::fake()->createWithContent('dpc-types.csv', $csv),
        ]);

        $department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $ao = User::factory()->assignedTo($department->id, Role::AuthorizedOfficer)->create();
        $from = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $to = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);

        $this->actingAs($ao)
            ->post(route('dpc-proposals.store'), $this->dpcStorePayload($this->sanctioningPreDpc($from, $to)))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, DpcProposal::query()->count());
    }
}
