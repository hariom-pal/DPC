<?php

declare(strict_types=1);

namespace Tests\Feature\Proceeding;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Enums\DpcProposalStatus;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Proceeding\Enums\DpcProceedingStatus;
use App\Domain\Proceeding\Models\DpcProceeding;
use App\Domain\Proceeding\Models\DpcProceedingOverride;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\Models\Audit;
use RuntimeException;
use Tests\TestCase;

/**
 * The Commission-correction record (PPT S33 EDIT / TRIBAL) — M7 Phase 1.
 *
 * **Model only.** Phase 2 builds the service and screens that record into it;
 * these tests pin the guarantees the model itself must carry, because those
 * guarantees are what let Phase 2 exist at all without touching frozen M4
 * data.
 */
class ProceedingOverrideTest extends TestCase
{
    use RefreshDatabase;

    private DpcProposal $proposal;

    private DpcProceeding $proceeding;

    private User $member;

    private ?DpcEvaluationRow $row = null;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $department = Department::query()->create([
            'code' => 'HE', 'name' => 'Higher Education Department', 'status' => 'active',
        ]);

        $this->member = User::factory()->create(['department_id' => null, 'name' => 'Member One']);
        $this->member->assignRole(Role::Member->value);

        $postFrom = Cadre::query()->create([
            'department_id' => $department->id, 'name' => 'Under Secretary',
            'class_level' => 'I', 'status' => 'active',
        ]);
        $postTo = Cadre::query()->create([
            'department_id' => $department->id, 'name' => 'Deputy Secretary',
            'class_level' => 'I', 'status' => 'active',
        ]);

        $this->proposal = DpcProposal::query()->create([
            'department_id' => $department->id,
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $postTo->id,
            'post_from_cadre_id' => $postFrom->id,
            'proposal_date' => '2026-06-25',
            'status' => DpcProposalStatus::Approved->value,
            'dpc_code' => 'HE202600001',
            'forwarded_at' => now(),
        ]);

        $this->proceeding = DpcProceeding::query()->create([
            'dpc_proposal_id' => $this->proposal->id,
            'status' => DpcProceedingStatus::InProceeding->value,
            'conducted_by' => $this->member->id,
            'conducted_by_name' => $this->member->name,
            'proposal_version' => str_repeat('a', 64),
            'opened_at' => now(),
        ]);
    }

    private function evaluationRow(): DpcEvaluationRow
    {
        return $this->row ??= $this->proposal->evaluationRows()->create([
            'sn' => 3,
            'name' => 'US3',
            'dob' => '1967-04-09',
            'reservation_category_id' => ReservationCategory::query()->firstOrFail()->id,
            'tribal_area_service_years' => null,
        ]);
    }

    private function override(array $attributes = []): DpcProceedingOverride
    {
        return DpcProceedingOverride::query()->create(array_merge([
            'dpc_proceeding_id' => $this->proceeding->id,
            'sequence' => ++$this->sequence,
            'dpc_evaluation_row_id' => $this->evaluationRow()->id,
            'sn' => 3,
            'officer_name' => 'US3',
            'field' => 'tribal_area_service_years',
            'year' => null,
            'previous_value' => null,
            'new_value' => '2021',
            'reason' => 'Tribal-area service confirmed from the department record at the sitting.',
            'applied_by' => $this->member->id,
            'applied_by_name' => $this->member->name,
            'applied_at' => now(),
        ], $attributes));
    }

    public function test_an_override_records_both_the_previous_and_the_new_value(): void
    {
        // Architect Directive 2. Storing only the new value would make the
        // correction history reconstructible solely by replaying M4 and every
        // prior override in order — the dependency the timeline must not have.
        $override = $this->override(['previous_value' => null, 'new_value' => '2021']);

        $this->assertNull($override->previous_value);
        $this->assertSame('2021', $override->new_value);
        $this->assertNotEmpty($override->reason);
        $this->assertSame($this->member->id, $override->applied_by);
        $this->assertNotNull($override->applied_at);
        $this->assertNotEmpty($override->uuid);
    }

    public function test_an_override_carries_everything_needed_to_replay_it(): void
    {
        $properties = $this->override()->toTimelineProperties();

        foreach ([
            'override_uuid', 'sn', 'officer_name', 'field', 'year',
            'previous_value', 'new_value', 'reason', 'applied_by_id',
            'applied_by_name', 'applied_at',
        ] as $key) {
            $this->assertArrayHasKey($key, $properties);
        }

        $this->assertSame('US3', $properties['officer_name']);
        $this->assertSame('2021', $properties['new_value']);
    }

    public function test_an_override_is_addressed_by_uuid(): void
    {
        $override = $this->override();

        $this->assertSame('uuid', $override->getRouteKeyName());
        $this->assertNotSame((string) $override->id, $override->uuid);
    }

    public function test_an_override_cannot_be_edited(): void
    {
        $override = $this->override();

        $this->expectException(RuntimeException::class);
        $override->update(['new_value' => '2099']);
    }

    public function test_an_override_cannot_be_deleted(): void
    {
        $override = $this->override();

        $this->expectException(RuntimeException::class);
        $override->delete();
    }

    public function test_the_reason_for_a_correction_cannot_be_rewritten(): void
    {
        // The reason is the justification a tribunal reads. If it could be
        // edited afterwards it would evidence nothing.
        $override = $this->override();

        $this->expectException(RuntimeException::class);
        $override->update(['reason' => 'A different reason entirely.']);
    }

    public function test_a_later_correction_supersedes_rather_than_overwrites(): void
    {
        $first = $this->override(['new_value' => '2019']);
        $second = $this->override(['previous_value' => '2019', 'new_value' => '2021']);

        $first->update(['superseded_by_id' => $second->id]);

        $this->assertSame(2, DpcProceedingOverride::query()->count());
        $this->assertSame($second->id, $first->fresh()->superseded_by_id);
        $this->assertSame('2019', $first->fresh()->new_value);

        $inForce = DpcProceedingOverride::query()->inForce()->get();
        $this->assertCount(1, $inForce);
        $this->assertSame($second->id, $inForce->first()->id);
    }

    public function test_a_superseded_override_is_still_immutable(): void
    {
        // The interesting case: a superseded row is the one a tamperer would
        // target, precisely because it looks inactive.
        $first = $this->override(['new_value' => '2019']);
        $second = $this->override(['previous_value' => '2019', 'new_value' => '2021']);
        $first->update(['superseded_by_id' => $second->id]);

        $this->expectException(RuntimeException::class);
        $first->fresh()->update(['new_value' => '2020']);
    }

    public function test_an_override_never_touches_the_frozen_evaluation_row(): void
    {
        // AQ-1: M4 data is read, never written. This is the whole reason the
        // override table exists.
        $row = $this->evaluationRow();
        $before = $row->tribal_area_service_years;

        DpcProceedingOverride::query()->create([
            'dpc_proceeding_id' => $this->proceeding->id,
            'dpc_evaluation_row_id' => $row->id,
            'sn' => $row->sn,
            'officer_name' => $row->name,
            'field' => 'tribal_area_service_years',
            'previous_value' => (string) $before,
            'new_value' => '2021',
            'reason' => 'Tribal-area service confirmed at the sitting.',
            'applied_by' => $this->member->id,
            'applied_by_name' => $this->member->name,
            'applied_at' => now(),
        ]);

        $this->assertSame($before, $row->fresh()->tribal_area_service_years);
    }

    public function test_an_override_is_audited(): void
    {
        $this->override();

        $this->assertSame(
            1,
            Audit::query()->where('auditable_type', (new DpcProceedingOverride)->getMorphClass())->count(),
        );
    }

    public function test_the_actor_name_is_snapshotted_on_the_override(): void
    {
        $override = $this->override();

        $this->member->update(['name' => 'Renamed Member']);

        $this->assertSame('Member One', $override->fresh()->applied_by_name);
    }
}
