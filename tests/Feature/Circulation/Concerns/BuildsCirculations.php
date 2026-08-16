<?php

declare(strict_types=1);

namespace Tests\Feature\Circulation\Concerns;

use App\Domain\Authentication\Models\User;
use App\Domain\Proceeding\Enums\HoldReason;
use App\Domain\Proceeding\Models\DpcProceedingHold;
use App\Domain\Proceeding\Services\HoldService;
use App\Domain\Proceeding\Services\ResultApprovalService;
use App\Enums\Role;
use Tests\Feature\Proceeding\Concerns\BuildsProceedings;

/**
 * A concluded DPC with two promotions still held — the arrangement every
 * M9 Phase 1 test starts from.
 *
 * The two officers are deliberately different **grounds**, because the whole
 * of Phase 1's BD-15 behaviour turns on telling them apart:
 *
 * - **US8** has a complete CR window and a missing 2016 immovable-property
 *   return — a `PropertyMissing` hold. This is the deck's own worked case
 *   (S44/S48 show `ST SS CIR`, and Super Seat is refused on an incomplete
 *   window, so US8's hold cannot be a CR one).
 * - **US9** has only two CR years recorded — a `CrYearsMissing` hold, the
 *   ground blocked on **BD-15**.
 */
trait BuildsCirculations
{
    use BuildsProceedings;

    protected User $administrativeHead;

    protected function bootCirculation(): void
    {
        $this->bootProceeding();

        $this->administrativeHead = User::factory()->create([
            'department_id' => $this->department->id,
            'name' => 'Administrative Head',
        ]);
        $this->administrativeHead->assignRole(Role::AdministrativeHead->value);

        // Property ground: five CR years, 2016 property return not recorded.
        $this->officer(8, 'US8', $this->stCategoryId(), [
            2013 => 'क+', 2014 => 'क+', 2015 => 'क+', 2016 => 'क+', 2017 => 'क+',
        ], [2016]);

        // CR ground: only two years recorded, against the five required.
        $this->officer(9, 'US9', $this->urCategoryId(), [
            2016 => 'क', 2017 => 'क',
        ]);

        app(HoldService::class)->detect($this->proceeding->fresh(), $this->memberOne);

        $this->calculate();

        app(ResultApprovalService::class)->approve(
            $this->proceeding->fresh(),
            $this->memberOne,
            request(),
            2017,
        );

        $this->proceeding = $this->proceeding->fresh();
        $this->proposal = $this->proposal->fresh();
    }

    protected function propertyHold(): DpcProceedingHold
    {
        return DpcProceedingHold::query()
            ->where('dpc_proceeding_id', $this->proceeding->id)
            ->where('reason_code', HoldReason::PropertyMissing->value)
            ->firstOrFail();
    }

    protected function crHold(): DpcProceedingHold
    {
        return DpcProceedingHold::query()
            ->where('dpc_proceeding_id', $this->proceeding->id)
            ->where('reason_code', HoldReason::CrYearsMissing->value)
            ->firstOrFail();
    }

    /**
     * The payload shape `CirculationService::raise()` and S47 both use.
     *
     * @return array<int, array{hold_uuid:string, resolution_detail:string, resolution_reference:string, resolution_received_on:string}>
     */
    protected function itemsFor(DpcProceedingHold ...$holds): array
    {
        return array_map(static fn (DpcProceedingHold $hold): array => [
            'hold_uuid' => $hold->uuid,
            'resolution_detail' => 'Record supplied by the department',
            'resolution_reference' => 'HE/DPC/2026/17',
            'resolution_received_on' => '2026-07-20',
        ], $holds);
    }
}
