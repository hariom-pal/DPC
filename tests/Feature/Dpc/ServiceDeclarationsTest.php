<?php

declare(strict_types=1);

namespace Tests\Feature\Dpc;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\EvaluationDocumentKind;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalService;
use App\Domain\Dpc\Services\EmployeeDetailsService;
use App\Domain\Master\Models\DepartmentPost;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RaisesDpcProposals;
use Tests\TestCase;

/**
 * Coder Instructions, per officer in Employee Details (2026-10-21): rule 4.4's orders
 * behind the date of birth and joining with the undertaking; rule 2.3's deputation
 * without a sanctioned setup; rule 4.4's promotion refused.
 */
class ServiceDeclarationsTest extends TestCase
{
    use RaisesDpcProposals;
    use RefreshDatabase;

    private User $admin;

    private User $ao;

    private DpcProposal $proposal;

    private DpcEvaluationRow $row;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        // A single pool: no reservation category is asked of the officer.
        config(['dpc.reservation.category_wise' => false]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);

        $department = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'active']);
        $this->ao = User::factory()->assignedTo($department->id, Role::AuthorizedOfficer)->create();
        $from = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Block Education Officer', 'class_level' => 'II', 'status' => 'active']);
        $to = DepartmentPost::query()->create(['department_id' => $department->id, 'name' => 'Additional Director', 'class_level' => 'I', 'status' => 'active']);

        $this->proposal = $this->raiseDpc($this->ao, $from, $to);
        app(DpcProposalService::class)->saveSeniorityEntries($this->proposal, [
            ['seniority_number' => 1, 'employee_number' => 'E-1', 'name' => 'Officer One', 'dob' => '1970-03-20', 'date_of_joining_service' => '1995-07-01', 'promotion_from_post' => 'Block Education Officer'],
        ], $this->ao, $this->proposal->seniorityReferenceDate());
        $this->row = $this->proposal->evaluationRows()->sole();
    }

    /**
     * @return array<string, list<string>>
     */
    private function sections(): array
    {
        return app(EmployeeDetailsService::class)->status($this->row->refresh())['sections'];
    }

    private function declare(array $data): TestResponse
    {
        return $this->actingAs($this->ao)->put(route('dpc-proposals.employees.declarations', [$this->proposal, $this->row]), $data);
    }

    private function upload(EvaluationDocumentKind $kind): TestResponse
    {
        return $this->actingAs($this->ao)->post(route('dpc-proposals.employees.documents.store', ['verified' => '1', $this->proposal, $this->row]), [
            'kind' => $kind->value,
            'document' => UploadedFile::fake()->create($kind->value.'.pdf', 20, 'application/pdf'),
        ]);
    }

    public function test_the_service_record_orders_and_undertaking_complete_basic_information(): void
    {
        $this->assertSame([
            __('dpc.employee_details.missing.dob_order'),
            __('dpc.employee_details.missing.joining_order'),
            __('dpc.employee_details.missing.service_record_undertaking'),
        ], $this->sections()['basic']);

        $basic = [
            'dob_order_number' => 'SR/12/1995', 'dob_order_date' => '1995-07-01',
            'joining_order_number' => 'APPT/45/1995', 'joining_order_date' => '1995-07-01',
        ];
        $this->actingAs($this->ao)->put(route('dpc-proposals.employees.basic', [$this->proposal, $this->row]), [...$basic, 'service_record_undertaking' => '0'])
            ->assertSessionHasNoErrors();
        $this->assertSame([__('dpc.employee_details.missing.service_record_undertaking')], $this->sections()['basic']);

        $this->actingAs($this->ao)->put(route('dpc-proposals.employees.basic', [$this->proposal, $this->row]), [...$basic, 'service_record_undertaking' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame([], $this->sections()['basic']);
        $this->assertNotNull($this->row->service_record_undertaking_at);
    }

    public function test_the_declarations_must_be_answered_and_evidenced(): void
    {
        $this->assertSame([__('dpc.employee_details.missing.declarations_unanswered')], $this->sections()['declarations']);

        // Nothing to declare.
        $this->declare(['unsanctioned_deputation' => '0', 'refused_promotion' => '0'])->assertSessionHasNoErrors();
        $this->assertSame([], $this->sections()['declarations']);
        $this->upload(EvaluationDocumentKind::DeputationOrder)->assertSessionHasErrors('declarations');

        // On deputation without a sanctioned setup: the order.
        $this->declare(['unsanctioned_deputation' => '1', 'refused_promotion' => '0'])->assertSessionHasNoErrors();
        $this->assertSame([__('dpc.employee_details.missing.deputation_order')], $this->sections()['declarations']);
        $this->upload(EvaluationDocumentKind::DeputationOrder)->assertSessionHasNoErrors();
        $this->assertSame([], $this->sections()['declarations']);

        // A refusal: its year, the letter and the DPC order.
        $this->declare(['unsanctioned_deputation' => '0', 'refused_promotion' => '1'])->assertSessionHasErrors('refused_promotion_year');
        $this->declare(['unsanctioned_deputation' => '0', 'refused_promotion' => '1', 'refused_promotion_year' => '2025'])->assertSessionHasNoErrors();
        $this->assertSame([
            __('dpc.employee_details.missing.refusal_letter'),
            __('dpc.employee_details.missing.refusal_dpc_order'),
        ], $this->sections()['declarations']);
        $this->upload(EvaluationDocumentKind::RefusalLetter)->assertSessionHasNoErrors();
        $this->upload(EvaluationDocumentKind::RefusalDpcOrder)->assertSessionHasNoErrors();
        $this->assertSame([], $this->sections()['declarations']);

        // 2025 is the year before this 2026 DPC: unfit.
        $this->assertTrue($this->row->refresh()->refusedPromotionLastYear());
        $this->actingAs($this->ao)->get(route('dpc-proposals.employees.show', [$this->proposal, $this->row]))
            ->assertOk()
            ->assertSee('id="declarations-not-eligible"', false);
    }

    public function test_a_refusal_before_last_year_does_not_make_the_officer_unfit(): void
    {
        $this->declare(['unsanctioned_deputation' => '0', 'refused_promotion' => '1', 'refused_promotion_year' => '2023'])->assertSessionHasNoErrors();

        $this->assertFalse($this->row->refresh()->refusedPromotionLastYear());
    }
}
