<?php

declare(strict_types=1);

namespace Tests\Feature\PreDpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\RecruitmentRule;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A Pre-DPC feeder that matches no cadre is **no longer dropped in silence**
 * (TD-25, reporting half).
 *
 * `projectToMasters()` resolves a text-only feeder by name within the department.
 * When nothing matches, the feeder is skipped and the recruitment rule is created
 * without it — so **the rule looks complete and sanctions nobody from that
 * cadre**, with nothing on any screen saying why. That combination, not the
 * omission alone, is what makes it worth reporting.
 *
 * **The behaviour is unchanged, deliberately.** Whether an unmatched feeder should
 * instead *refuse* the approval is a workflow change and the Architect's decision;
 * the remaining half of TD-25 stays open. So the first test below asserts the
 * omission still happens — pinning it, so that if the refusal is ever ruled, this
 * is the test that has to be revisited rather than a behaviour that changes
 * unnoticed.
 */
class UnmatchedFeederReportTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $ah;

    private Cadre $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::query()->create([
            'code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active',
        ]);

        $this->ao = User::factory()->create(['department_id' => $this->department->id]);
        $this->ao->assignRole(Role::AuthorizedOfficer->value);
        $this->ah = User::factory()->create(['department_id' => $this->department->id]);
        $this->ah->assignRole(Role::AdministrativeHead->value);

        $this->target = Cadre::query()->create([
            'department_id' => $this->department->id, 'name' => 'Deputy Secretary',
            'class_level' => 'I', 'status' => MasterStatus::Active->value,
        ]);
    }

    /**
     * Approve a Pre-DPC whose single feeder is named only as text.
     *
     * `feeder_cadre_id` is deliberately absent, which is the path a proposal
     * drafted before 2026-08-18, the demo seeder, or any API caller still takes.
     */
    private function approveWithTextFeeder(string $feederName): PreDpcProposal
    {
        Storage::fake('local');

        $this->actingAs($this->ao)->post(route('pre-dpc.store'), [
            'government_order_number' => 'GO/2026/HE/1',
            'effective_from' => '2026-01-01',
            'posts' => [[
                'cadre_id' => $this->target->id,
                'class_level' => 'I',
                'direct_recruitment_pct' => 0,
                'promotion_recruitment_pct' => 100,
                'feeders' => [[
                    'feeder_name' => $feederName,
                    'promotion_percentage' => 100,
                    'min_experience_years' => 5,
                ]],
            ]],
        ])->assertSessionHasNoErrors();

        $proposal = PreDpcProposal::query()->latest('id')->firstOrFail();
        $this->actingAs($this->ao)->post(route('pre-dpc.submit', $proposal));
        $this->actingAs($this->ah)->post(route('pre-dpc.approve', $proposal));

        return $proposal->refresh();
    }

    public function test_the_projection_logs_a_warning_naming_the_feeder_it_dropped(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'matched no cadre')
                    && $context['feeder_name'] === 'Section Officer'
                    && $context['post_name'] === 'Deputy Secretary'
                    && $context['technical_debt'] === 'TD-25';
            });

        // Other log levels are the framework's business, not this test's.
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $this->approveWithTextFeeder('Section Officer');
    }

    /**
     * The omission itself, pinned rather than accepted.
     *
     * A rule with no feeder is exactly what makes TD-25 worth reporting: it
     * promotes nobody, and it does not look broken.
     */
    public function test_the_rule_is_still_created_without_the_unmatched_feeder(): void
    {
        $this->approveWithTextFeeder('Section Officer');

        $rule = RecruitmentRule::query()->where('cadre_id', $this->target->id)->firstOrFail();

        $this->assertSame(0, $rule->feeders()->count(), 'behaviour is unchanged: the feeder is omitted, not repaired');
    }

    public function test_the_report_names_the_department_version_post_and_feeder(): void
    {
        $proposal = $this->approveWithTextFeeder('Section Officer');

        // Asserted against the whole buffer rather than with a chain of
        // `expectsOutputToContain`: the department, version, post and feeder all
        // sit on **one** table row, and each chained expectation consumes the
        // first line that satisfies it — so the second one would fail on a row
        // that plainly contains it.
        $this->assertSame(0, Artisan::call('pre-dpc:unmatched-feeders'));

        $output = Artisan::output();

        $this->assertStringContainsString('1 Pre-DPC feeder(s) match no cadre', $output);
        $this->assertStringContainsString('Section Officer', $output);
        $this->assertStringContainsString('Deputy Secretary', $output);
        $this->assertStringContainsString('v'.$proposal->version, $output);
        $this->assertStringContainsString((string) $this->department->id, $output);
    }

    public function test_the_report_is_silent_when_every_feeder_resolves(): void
    {
        Cadre::query()->create([
            'department_id' => $this->department->id, 'name' => 'Under Secretary',
            'class_level' => 'I', 'status' => MasterStatus::Active->value,
        ]);

        // Named as text, but the master now has a row of that name — which is the
        // legacy path working, not a finding.
        $this->approveWithTextFeeder('Under Secretary');

        $this->artisan('pre-dpc:unmatched-feeders')
            ->expectsOutputToContain('Every feeder on every approved Pre-DPC resolves')
            ->assertExitCode(0);
    }

    /**
     * A draft is not yet a finding. Nothing has projected, so nothing has been
     * lost, and the AO can still fix it — reporting it would train the reader to
     * ignore this command.
     */
    public function test_a_draft_is_reported_only_under_all(): void
    {
        $this->actingAs($this->ao)->post(route('pre-dpc.store'), [
            'government_order_number' => 'GO/2026/HE/2',
            'effective_from' => '2026-01-01',
            'posts' => [[
                'cadre_id' => $this->target->id,
                'class_level' => 'I',
                'direct_recruitment_pct' => 0,
                'promotion_recruitment_pct' => 100,
                'feeders' => [[
                    'feeder_name' => 'Assistant Grade-1',
                    'promotion_percentage' => 100,
                    'min_experience_years' => 5,
                ]],
            ]],
        ])->assertSessionHasNoErrors();

        $this->artisan('pre-dpc:unmatched-feeders')
            ->expectsOutputToContain('Every feeder on every approved Pre-DPC resolves')
            ->assertExitCode(0);

        $this->artisan('pre-dpc:unmatched-feeders', ['--all' => true])
            ->expectsOutputToContain('Assistant Grade-1')
            ->assertExitCode(0);
    }
}
