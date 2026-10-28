<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Authentication\Models\User;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\UserManagement\Models\UserDepartmentAssignment;
use App\Enums\Role;
use Illuminate\Testing\TestResponse;

/**
 * A Pre-DPC as it is made since 2026-10-26 (A1): the Department Admin opens it —
 * DPC year and promotion-to post — and it is allotted to an AO and an AH (since
 * 2026-10-28 the Department Admin's last step; here, so the AO can fill it in).
 */
trait OpensPreDpcs
{
    use AllotsProposals;

    /**
     * Open as the Department Admin, then save the AO's form. When the Department
     * Admin's step is refused (the year or the post), that response is returned
     * and nothing is opened.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function storePreDpc(User $ao, array $payload, ?User $ah = null): TestResponse
    {
        $opened = $this->openPreDpc($ao, $payload['dpc_year'] ?? null, $payload['promotion_to_post_id'] ?? null, $ah);

        if ($opened instanceof TestResponse) {
            return $opened;
        }

        return $this->actingAs($ao)->put(route('pre-dpc.update', $opened), $payload);
    }

    /**
     * The Department Admin's step alone: the opened draft, or the refusal.
     */
    protected function openPreDpc(User $ao, mixed $year, mixed $postId, ?User $ah = null): PreDpcProposal|TestResponse
    {
        $departmentId = (int) $ao->department_id;
        $ah ??= $this->firstOfficer($departmentId, Role::AdministrativeHead);
        $before = PreDpcProposal::query()->max('id');

        $admin = $this->departmentAdminOf($departmentId);
        $response = $this->actingAs($admin)->post(route('department.allotments.pre-dpc.store'), [
            'dpc_year' => $year,
            'promotion_to_post_id' => $postId,
        ]);

        $opened = PreDpcProposal::query()->where('id', '>', (int) $before)->latest('id')->first();

        if ($opened === null) {
            return $response;
        }

        // The AO and AH are the Department Admin's last step once the Pre-DPC is
        // complete (2026-10-28); a fixture that has the AO fill it in allots first.
        app(ProposalAllotmentService::class)->allot($opened, $ao, $ah, $admin);

        return $opened;
    }

    protected function departmentAdminOf(int $departmentId): User
    {
        $id = UserDepartmentAssignment::query()->active()
            ->where('department_id', $departmentId)
            ->where('role', Role::DepartmentAdmin->value)
            ->value('user_id');

        return $id !== null
            ? User::query()->findOrFail($id)
            : User::factory()->assignedTo($departmentId, Role::DepartmentAdmin)->create();
    }
}
