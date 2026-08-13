<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\VacancyType;
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CompletesDpcProposals;
use Tests\TestCase;

class DpcWorkingOfficersTest extends TestCase
{
    use CompletesDpcProposals;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private DpcProposal $proposal;

    private VacancyType $clearVacancy;

    private ReservationCategory $ur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $this->department = Department::query()->create(['code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active']);
        $this->ao = User::factory()->create(['department_id' => $this->department->id]);
        $this->ao->assignRole(Role::AuthorizedOfficer->value);

        $postFrom = Cadre::query()->create(['department_id' => $this->department->id, 'name' => 'Under Secretary', 'class_level' => 'I', 'status' => 'active']);
        $postTo = Cadre::query()->create(['department_id' => $this->department->id, 'name' => 'Deputy Secretary', 'class_level' => 'I', 'status' => 'active']);

        $this->clearVacancy = VacancyType::query()->where('code', 'clear')->firstOrFail();
        $this->ur = ReservationCategory::query()->where('code', 'UR')->firstOrFail();

        $this->actingAs($this->ao)->post(route('dpc-proposals.store'), [
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $postTo->id,
            'post_from_cadre_id' => $postFrom->id,
            'proposal_date' => '2026-06-25',
        ]);
        $this->proposal = DpcProposal::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'sanctioned_post_count' => 4,
            'vacancies' => [
                ['vacancy_type_id' => $this->clearVacancy->id, 'reservation_category_id' => $this->ur->id, 'count' => 1],
            ],
            'officers' => [
                ['sn' => 1, 'name' => 'DS1', 'dob' => '1972-03-20', 'reservation_category_id' => $this->ur->id],
            ],
        ], $overrides);
    }

    public function test_ao_can_save_vacancies_and_working_officers(): void
    {
        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload())
            ->assertRedirect(route('dpc-proposals.show', $this->proposal));

        $this->proposal->refresh();
        $this->assertSame(4, $this->proposal->sanctioned_post_count);
        $this->assertSame(1, $this->proposal->vacancies()->count());
        $this->assertSame(1, $this->proposal->workingOfficers()->count());
        $this->assertSame('DS1', $this->proposal->workingOfficers()->firstOrFail()->name);
    }

    public function test_working_officer_stores_the_full_standard_employee_snapshot(): void
    {
        // Architect directive, 2026-08-13: every proposal snapshot table
        // carries the same ten officer fields.
        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload([
            'officers' => [[
                'sn' => 1,
                'employee_number' => 'EMP-77',
                'name' => 'DS1',
                'dob' => '1972-03-20',
                'qualification' => 'M.A.',
                'current_post' => 'Deputy Secretary',
                'feeder_post' => 'Under Secretary',
                'reservation_category_id' => $this->ur->id,
                'date_of_joining_service' => '1995-04-01',
                'date_of_posting_on_post' => '2019-07-15',
                'date_of_posting_on_feeder_post' => '2012-01-10',
            ]],
        ]));

        $officer = $this->proposal->workingOfficers()->firstOrFail();

        $this->assertSame('EMP-77', $officer->employee_number);
        $this->assertSame('M.A.', $officer->qualification);
        $this->assertSame('Deputy Secretary', $officer->current_post);
        $this->assertSame('Under Secretary', $officer->feeder_post);
        $this->assertSame($this->ur->id, $officer->reservation_category_id);
        $this->assertSame('1995-04-01', $officer->date_of_joining_service?->format('Y-m-d'));
        $this->assertSame('2019-07-15', $officer->date_of_posting_on_post?->format('Y-m-d'));
        $this->assertSame('2012-01-10', $officer->date_of_posting_on_feeder_post?->format('Y-m-d'));
    }

    public function test_vacancy_type_and_reservation_category_must_reference_real_masters(): void
    {
        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload([
            'vacancies' => [['vacancy_type_id' => 999999, 'reservation_category_id' => $this->ur->id, 'count' => 1]],
        ]))->assertSessionHasErrors('vacancies.0.vacancy_type_id');
    }

    public function test_duplicate_vacancy_type_and_category_pair_is_rejected(): void
    {
        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload([
            'vacancies' => [
                ['vacancy_type_id' => $this->clearVacancy->id, 'reservation_category_id' => $this->ur->id, 'count' => 1],
                ['vacancy_type_id' => $this->clearVacancy->id, 'reservation_category_id' => $this->ur->id, 'count' => 2],
            ],
        ]))->assertSessionHasErrors('vacancies');
    }

    public function test_sanctioned_post_count_is_required(): void
    {
        $payload = $this->payload();
        unset($payload['sanctioned_post_count']);

        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $payload)
            ->assertSessionHasErrors('sanctioned_post_count');
    }

    public function test_vacancies_and_working_officers_are_immutable_once_submitted(): void
    {
        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload());
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $this->actingAs($this->ao)->get(route('dpc-proposals.working-officers', $this->proposal))->assertForbidden();
        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload())->assertForbidden();
    }

    public function test_service_layer_rejects_mutation_after_submission_even_bypassing_http(): void
    {
        $this->actingAs($this->ao)->put(route('dpc-proposals.working-officers.update', $this->proposal), $this->payload());
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $service = app(DpcProposalService::class);
        $submitted = $this->proposal->fresh();

        $this->expectException(WorkflowException::class);
        $service->saveWorkingOfficers($submitted, [['sn' => 1, 'name' => 'Tampered', 'dob' => '1970-01-01', 'reservation_category_id' => $this->ur->id]]);
    }
}
