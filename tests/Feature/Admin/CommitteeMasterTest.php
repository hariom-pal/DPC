<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Committee\Enums\MemberCategory;
use App\Domain\Committee\Services\CommitteeRuleResolver;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\CommitteeConfiguration;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The DPC Committee Rules: one committee, the Chairman by rule, and who else must
 * sit — all the Administrator's.
 */
class CommitteeMasterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rules(array $overrides = []): array
    {
        return [
            'members_min' => '3',
            'members_max' => '6',
            'required_categories' => ['sc', 'st', 'obc', 'female', 'bhar_sadhak_sachiv', 'vibhag_sachiv'],
            'requires_reserved_category' => '0',
            'effective_from' => '2026-01-01',
            'status' => MasterStatus::Active->value,
            ...$overrides,
        ];
    }

    private function proposalOn(string $date): DpcProposal
    {
        return (new DpcProposal)->forceFill(['proposal_date' => $date]);
    }

    public function test_there_is_no_committee_type_to_choose(): void
    {
        $this->assertFalse(Route::has('admin.committee-types.index'));

        $this->actingAs($this->admin())->get(route('admin.committee-configurations.create'))
            ->assertOk()
            ->assertSee(__('admin.committee_rules.chairman_fixed'))
            ->assertSee('name="required_categories[]"', false)
            ->assertDontSee('committee_type_id')
            ->assertDontSee('name="quorum"', false);
    }

    public function test_the_administrator_states_the_size_besides_the_chairman_and_who_must_sit(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.committee-configurations.store'), $this->rules())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.committee-configurations.index'));

        $config = CommitteeConfiguration::query()->sole();
        // Stored as the whole committee, the Chairman's seat included.
        $this->assertSame(4, $config->min_members);
        $this->assertSame(7, $config->max_members);
        $this->assertSame(['sc', 'st', 'obc', 'female', 'bhar_sadhak_sachiv', 'vibhag_sachiv'], $config->required_categories);

        $rules = app(CommitteeRuleResolver::class)->forProposal($this->proposalOn('2026-06-01'));
        $this->assertSame(3, $rules->minMembers);
        $this->assertSame(6, $rules->maxMembers);
        $this->assertTrue($rules->chairmanRequired);
        $this->assertCount(6, $rules->requiredCategories);
    }

    public function test_the_committee_is_checked_against_the_administrator_s_composition_not_configuration(): void
    {
        config(['dpc.committee.required_categories' => ['sc', 'st', 'obc', 'female', 'bhar_sadhak_sachiv', 'vibhag_sachiv']]);

        $this->actingAs($this->admin())
            ->post(route('admin.committee-configurations.store'), $this->rules([
                'required_categories' => ['female', 'vibhag_sachiv'],
                'requires_reserved_category' => '1',
            ]))
            ->assertSessionHasNoErrors();

        $rules = app(CommitteeRuleResolver::class)->forProposal($this->proposalOn('2026-06-01'));
        $this->assertSame([MemberCategory::Female, MemberCategory::VibhagSachiv], $rules->requiredCategories);
        $this->assertTrue($rules->requiresReservedCategory);

        // Before the rules took effect, configuration's defaults apply.
        $earlier = app(CommitteeRuleResolver::class)->forProposal($this->proposalOn('2025-06-01'));
        $this->assertCount(6, $earlier->requiredCategories);
        $this->assertFalse($earlier->requiresReservedCategory);
    }

    public function test_impossible_rules_are_refused_beside_their_field(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.committee-configurations.store'), $this->rules(['required_categories' => ['sc', 'bogus']]))
            ->assertSessionHasErrors('required_categories.1');

        // SC, ST and OBC are three different people.
        $this->actingAs($admin)
            ->post(route('admin.committee-configurations.store'), $this->rules(['members_min' => '1', 'members_max' => '2']))
            ->assertSessionHasErrors('members_max');

        $this->actingAs($admin)
            ->post(route('admin.committee-configurations.store'), $this->rules(['members_min' => '5', 'members_max' => '4']))
            ->assertSessionHasErrors('members_max');

        $this->assertSame(0, CommitteeConfiguration::query()->count());
    }

    public function test_only_the_administrator_manages_the_rules(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $officer = User::factory()->create();
        $officer->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($officer)->post(route('admin.committee-configurations.store'), $this->rules())->assertForbidden();
        $this->assertSame(0, CommitteeConfiguration::query()->count());
    }
}
