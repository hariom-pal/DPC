<?php

declare(strict_types=1);

namespace Tests\Feature\Committee;

use App\Domain\Authentication\Models\User;
use App\Domain\Committee\Services\CommitteeDesignationLevel;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\Designation;
use App\Domain\Master\Models\PayScale;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * A committee member's designation is at the feeder post's level, above it, or one
 * level below (MoM of 12 November 2026, point 4): the level read from the Department
 * Post mapping — its pay scale, else its class.
 */
class CommitteeDesignationLevelTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private Department $department;

    private DpcProposal $proposal;

    private User $ao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, LookupMasterSeeder::class]);
        PayScale::query()->update(['status' => MasterStatus::Inactive->value]);

        $this->department = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();

        $l8 = $this->scale('L8', 47600);
        $l10 = $this->scale('L10', 56100);
        $l12 = $this->scale('L12', 78800);
        $l14 = $this->scale('L14', 123100);

        $from = $this->mapPost('Block Education Officer', 'II', $l10);
        $to = $this->mapPost('Deputy Director', 'I', $l12);
        $this->mapPost('Joint Director', 'I', $l14);
        $this->mapPost('Principal', 'II', $l8);
        // Class only: no pay scale on its mapping.
        $this->mapPost('Senior Lecturer', 'III', null);
        $this->mapPost('Lecturer', 'II', null);

        $this->proposal = $this->raiseDpc($this->ao, $from, $to);
    }

    private function scale(string $code, int $minimum): PayScale
    {
        return PayScale::query()->create(['code' => $code, 'label' => $code, 'minimum_pay' => $minimum, 'status' => MasterStatus::Active->value]);
    }

    private function mapPost(string $name, string $class, ?PayScale $scale): DepartmentPost
    {
        Designation::query()->firstOrCreate(['name' => $name], ['status' => MasterStatus::Active->value]);

        return DepartmentPost::query()->create([
            'department_id' => $this->department->id, 'name' => $name, 'class_level' => $class,
            'pay_scale_id' => $scale?->id, 'retirement_age' => 62, 'status' => MasterStatus::Active->value,
        ]);
    }

    public function test_the_level_is_the_mappings_pay_scale_else_its_class(): void
    {
        $levels = app(CommitteeDesignationLevel::class);
        $proposal = $this->proposal->fresh();

        // Feeder: Block Education Officer, L10, Class II.
        $this->assertTrue($levels->allows($proposal, 'Block Education Officer'), 'The same level.');
        $this->assertTrue($levels->allows($proposal, 'Joint Director'), 'Above.');
        $this->assertTrue($levels->allows($proposal, 'Principal'), 'One pay scale below (L8).');
        $this->assertTrue($levels->allows($proposal, 'Lecturer'), 'No pay scale: Class II, the same class.');
        $this->assertTrue($levels->allows($proposal, 'Senior Lecturer'), 'No pay scale: Class III is one class below Class II.');

        // Class IV, no pay scale: two classes below.
        $this->mapPost('Peon', 'IV', null);
        $this->assertFalse($levels->allows($proposal, 'Peon'));
    }

    public function test_more_than_one_level_below_is_not_offered_or_saved(): void
    {
        // A scale below L8: two steps under the feeder's L10.
        $l6 = $this->scale('L6', 35400);
        $this->mapPost('Assistant Teacher', 'III', $l6);

        $levels = app(CommitteeDesignationLevel::class);
        $this->assertFalse($levels->allows($this->proposal->fresh(), 'Assistant Teacher'));
        $this->assertTrue($levels->allows($this->proposal->fresh(), 'Secretary, GAD'), 'No mapping: nothing to compare, still offered.');

        $page = $this->actingAs($this->ao)->get(route('dpc-proposals.committee', $this->proposal))->assertOk()->getContent();
        $this->assertStringNotContainsString('>Assistant Teacher<', (string) $page);
        $this->assertStringContainsString('Principal', (string) $page);
    }
}
