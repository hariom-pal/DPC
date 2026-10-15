<?php

declare(strict_types=1);

namespace Tests\Feature\Committee;

use App\Domain\Authentication\Models\User;
use App\Domain\Committee\Services\CommitteeBoundsReconciler;
use App\Domain\Master\Models\CommitteeConfiguration;
use App\Domain\Master\Models\CommitteeType;
use App\Enums\Role;
use Database\Seeders\CommitteeMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suggestion 2: a committee size registered before change item 15 is reported,
 * and corrected only when the Administrator asks.
 *
 * The gap this closes is a data gap, not a code gap — the rule the register asked
 * for is in force on a fresh installation and silently absent on a running one,
 * because the master row wins and a seeder does not run on a live database.
 */
class CommitteeBoundsReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(CommitteeMasterSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);
    }

    private function dpcConfiguration(): CommitteeConfiguration
    {
        return CommitteeConfiguration::query()
            ->whereHas('committeeType', fn ($q) => $q->where('code', 'dpc_committee'))
            ->sole();
    }

    private function reconciler(): CommitteeBoundsReconciler
    {
        return app(CommitteeBoundsReconciler::class);
    }

    public function test_the_seeded_configuration_already_states_the_register_s_bounds(): void
    {
        $this->assertNull(
            $this->reconciler()->drift($this->dpcConfiguration()),
            'A freshly seeded installation must not be reported as drifting from the rule it was seeded with.',
        );
    }

    public function test_a_configuration_from_before_the_rule_is_reported(): void
    {
        // What an installation seeded before 2026-08-24 holds.
        $configuration = $this->dpcConfiguration();
        $configuration->forceFill(['min_members' => 1, 'max_members' => 5])->save();

        $drift = $this->reconciler()->drift($configuration->fresh());

        $this->assertNotNull($drift);
        $this->assertSame(['min' => 0, 'max' => 4], $drift['stored']);
        $this->assertSame(['min' => 3, 'max' => 6], $drift['configured']);
    }

    public function test_another_committee_type_is_not_the_register_s_business(): void
    {
        // Not seeded by CommitteeMasterSeeder — the DPC committee is the only type
        // the register speaks about, which is the point of this test.
        $type = CommitteeType::query()->create([
            'code' => 'departmental_screening_committee',
            'name_en' => 'Departmental Screening Committee',
            'name_hi' => 'विभागीय स्क्रीनिंग समिति',
            'status' => 'active',
        ]);

        $other = CommitteeConfiguration::query()->create([
            'committee_type_id' => $type->id,
            'min_members' => 2, 'max_members' => 4, 'chairman_mandatory' => true, 'quorum' => 2,
            'voting_method' => 'chairman_decision', 'majority_type' => 'not_applicable',
            'tie_break_rule' => 'chairman_decision', 'digital_signature_required' => true,
            'remarks_required' => true, 'external_member_allowed' => false, 'secretary_required' => true,
            'status' => 'active',
        ]);

        $this->assertNull($this->reconciler()->drift($other));
    }

    public function test_the_screen_reports_the_difference_and_the_administrator_applies_it(): void
    {
        $configuration = $this->dpcConfiguration();
        $configuration->forceFill(['min_members' => 1, 'max_members' => 5])->save();

        $this->actingAs($this->admin)->get(route('admin.committee-configurations.index'))
            ->assertOk()
            ->assertSee('predates the current rule');

        $this->actingAs($this->admin)
            ->post(route('admin.committee-configurations.align-bounds', $configuration->id))
            ->assertRedirect(route('admin.committee-configurations.index'));

        // Stored as whole-committee counts, which is what the column has always meant.
        $configuration->refresh();
        $this->assertSame(4, $configuration->min_members);
        $this->assertSame(7, $configuration->max_members);
        $this->assertNull($this->reconciler()->drift($configuration));

        $this->assertDatabaseHas('activity_timelines', ['event' => 'committee_configuration.bounds_aligned']);
    }

    public function test_the_notice_is_absent_when_nothing_has_drifted(): void
    {
        $this->actingAs($this->admin)->get(route('admin.committee-configurations.index'))
            ->assertOk()
            ->assertDontSee('predates the current rule');
    }

    public function test_only_the_administrator_may_apply_it(): void
    {
        $officer = User::factory()->create();
        $officer->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($officer)
            ->post(route('admin.committee-configurations.align-bounds', $this->dpcConfiguration()->id))
            ->assertForbidden();
    }
}
