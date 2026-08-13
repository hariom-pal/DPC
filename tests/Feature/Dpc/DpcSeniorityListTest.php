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
use App\Domain\Shared\Workflow\WorkflowException;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CompletesDpcProposals;
use Tests\TestCase;

class DpcSeniorityListTest extends TestCase
{
    use CompletesDpcProposals;
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private DpcProposal $proposal;

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
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function payload(array $entries): array
    {
        return ['entries' => $entries];
    }

    private function entry(int $sn, array $overrides = []): array
    {
        return array_merge([
            'seniority_number' => $sn,
            'employee_number' => "EMP-{$sn}",
            'name' => "US{$sn}",
            'dob' => '1964-03-20',
            'qualification' => '11th',
            'current_post' => 'Under Secretary',
            'feeder_post' => 'Under Secretary',
            'reservation_category_id' => $this->ur->id,
            'date_of_joining_service' => '1985-04-01',
            'date_of_posting_on_current_post' => '2015-06-01',
            'date_of_posting_on_feeder_post' => '2012-01-15',
        ], $overrides);
    }

    public function test_seniority_entry_stores_the_full_standard_employee_snapshot(): void
    {
        // Architect directive, 2026-08-13: every proposal snapshot table
        // carries the same ten officer fields. `date_of_posting_on_feeder_post`
        // is the PPT S17/S24 field added on the Phase 4 review.
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.seniority-list.update', $this->proposal),
            $this->payload([$this->entry(1)]),
        );

        $entry = $this->proposal->seniorityEntries()->firstOrFail();

        $this->assertSame('EMP-1', $entry->employee_number);
        $this->assertSame('11th', $entry->qualification);
        $this->assertSame('Under Secretary', $entry->current_post);
        $this->assertSame('Under Secretary', $entry->feeder_post);
        $this->assertSame($this->ur->id, $entry->reservation_category_id);
        $this->assertSame('1985-04-01', $entry->date_of_joining_service?->format('Y-m-d'));
        $this->assertSame('2015-06-01', $entry->date_of_posting_on_current_post?->format('Y-m-d'));
        $this->assertSame('2012-01-15', $entry->date_of_posting_on_feeder_post?->format('Y-m-d'));
    }

    public function test_ao_can_save_the_seniority_list(): void
    {
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.seniority-list.update', $this->proposal),
            $this->payload([$this->entry(1), $this->entry(2)]),
        )->assertRedirect(route('dpc-proposals.show', $this->proposal));

        $this->proposal->refresh();
        $this->assertSame(2, $this->proposal->seniorityEntries()->count());
        $first = $this->proposal->seniorityEntries()->where('seniority_number', 1)->firstOrFail();
        $this->assertSame('EMP-1', $first->employee_number);
        $this->assertSame('Under Secretary', $first->feeder_post);
    }

    public function test_reservation_category_must_reference_a_real_master(): void
    {
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.seniority-list.update', $this->proposal),
            $this->payload([$this->entry(1, ['reservation_category_id' => 999999])]),
        )->assertSessionHasErrors('entries.0.reservation_category_id');
    }

    public function test_duplicate_seniority_number_is_rejected(): void
    {
        $this->actingAs($this->ao)->put(
            route('dpc-proposals.seniority-list.update', $this->proposal),
            $this->payload([$this->entry(1), $this->entry(1)]),
        )->assertSessionHasErrors('entries');
    }

    public function test_seniority_list_is_immutable_once_submitted(): void
    {
        $this->actingAs($this->ao)->put(route('dpc-proposals.seniority-list.update', $this->proposal), $this->payload([$this->entry(1)]));
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $this->actingAs($this->ao)->get(route('dpc-proposals.seniority-list', $this->proposal))->assertForbidden();
        $this->actingAs($this->ao)->put(route('dpc-proposals.seniority-list.update', $this->proposal), $this->payload([$this->entry(2)]))->assertForbidden();
    }

    public function test_service_layer_rejects_mutation_after_submission_even_bypassing_http(): void
    {
        $this->actingAs($this->ao)->put(route('dpc-proposals.seniority-list.update', $this->proposal), $this->payload([$this->entry(1)]));
        $this->makeProposalSubmittable($this->proposal);
        $this->actingAs($this->ao)->post(route('dpc-proposals.submit', $this->proposal));

        $service = app(DpcProposalService::class);
        $submitted = $this->proposal->fresh();

        $this->expectException(WorkflowException::class);
        $service->saveSeniorityEntries($submitted, [$this->entry(99)]);
    }
}
