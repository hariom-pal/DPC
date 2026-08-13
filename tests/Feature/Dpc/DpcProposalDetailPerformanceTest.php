<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proposal Detail (S12–S18 read view) must not issue per-row queries
 * (architect directive, 2026-08-13: eager load working officers, seniority
 * entries, and reservation category; avoid N+1).
 *
 * The assertion is growth-based rather than a fixed budget: the same screen
 * is rendered with a small and a large data set, and the query count must not
 * move. A fixed number would break on every unrelated framework or auth
 * query; a flat curve is the property that actually matters.
 */
class DpcProposalDetailPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private ReservationCategory $ur;

    private Cadre $postTo;

    private Cadre $postFrom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->create(['department_id' => $this->department->id]);
        $this->ao->assignRole(Role::AuthorizedOfficer->value);

        $this->postFrom = Cadre::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $this->postTo = Cadre::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);
        $this->ur = ReservationCategory::query()->where('code', 'UR')->firstOrFail();
    }

    private function buildProposal(int $officerCount): DpcProposal
    {
        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), [
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $this->postTo->id,
            'post_from_cadre_id' => $this->postFrom->id,
            'proposal_date' => '2026-06-25',
        ]);
        $proposal = DpcProposal::query()->latest('id')->firstOrFail();

        $officers = [];
        $entries = [];
        $rows = [];

        foreach (range(1, $officerCount) as $n) {
            $officers[] = [
                'sn' => $n,
                'employee_number' => "EMP-{$n}",
                'name' => "Officer {$n}",
                'dob' => '1964-03-20',
                'reservation_category_id' => $this->ur->id,
            ];
            $entries[] = [
                'seniority_number' => $n,
                'name' => "Officer {$n}",
                'dob' => '1964-03-20',
                'reservation_category_id' => $this->ur->id,
            ];
            $rows[] = [
                'sn' => $n,
                'name' => "Officer {$n}",
                'dob' => '1964-03-20',
                'reservation_category_id' => $this->ur->id,
                'cr_grades' => [['year' => 2015, 'grade' => 'क'], ['year' => 2016, 'grade' => 'ख']],
                'loyalty_flags' => [['year' => 2015, 'flag' => '1'], ['year' => 2016, 'flag' => '1']],
                'property_flags' => [['year' => 2015, 'flag' => '1'], ['year' => 2016, 'flag' => '0']],
            ];
        }

        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $proposal), [
            'sanctioned_post_count' => 10,
            'vacancies' => [[
                'vacancy_type_id' => VacancyType::query()->firstOrFail()->id,
                'reservation_category_id' => $this->ur->id,
                'count' => 1,
            ]],
            'officers' => $officers,
        ]);
        $this->actingAs($this->ao)->put(route('dpc-proposals.seniority-list.update', $proposal), ['entries' => $entries]);
        $this->actingAs($this->ao)->put(route('dpc-proposals.evaluation-chart.update', $proposal), ['rows' => $rows]);

        return $proposal;
    }

    private function queryCountForDetail(DpcProposal $proposal): int
    {
        $count = 0;
        DB::flushQueryLog();
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->actingAs($this->ao)->get(route('dpc-proposals.show', $proposal))->assertOk();

        return $count;
    }

    public function test_proposal_detail_query_count_does_not_grow_with_officer_count(): void
    {
        $small = $this->buildProposal(2);
        $large = $this->buildProposal(25);

        $smallCount = $this->queryCountForDetail($small);
        $largeCount = $this->queryCountForDetail($large);

        $this->assertSame(
            $smallCount,
            $largeCount,
            "Proposal Detail issued {$largeCount} queries for 25 officers vs {$smallCount} for 2 — a section is not eager loaded.",
        );
    }
}
