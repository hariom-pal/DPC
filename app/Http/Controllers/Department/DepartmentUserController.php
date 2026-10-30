<?php

declare(strict_types=1);

namespace App\Http\Controllers\Department;

use App\Domain\Allotment\Models\ProposalAllotment;
use App\Domain\Authentication\Models\User;
use App\Domain\Signature\Services\EsignGate;
use App\Domain\UserManagement\Exceptions\AssignmentRefused;
use App\Domain\UserManagement\Services\DepartmentUserAuthority;
use App\Domain\UserManagement\Services\OfficerPool;
use App\Enums\OfficerChangeReason;
use App\Http\Controllers\Controller;
use App\Http\SignedActions\ReleaseOfficer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * User Management for the Department Admin (2026-10-29): the officers registered
 * in the department, and **Release** for an AO/AH who has been transferred. A
 * released officer may then be chosen by another department. Viewing and
 * releasing only — officers are added from the AO/AH picker.
 */
class DepartmentUserController extends Controller
{
    public function __construct(
        private readonly DepartmentUserAuthority $authority,
        private readonly OfficerPool $pool,
        private readonly EsignGate $gate,
    ) {}

    public function index(Request $request): View
    {
        $admin = $this->admin($request);
        $departmentId = (int) $admin->department_id;

        $officers = $this->pool->members($departmentId)
            ->with(['designation', 'activeAssignments' => fn ($q) => $q->where('department_id', $departmentId)])
            ->orderBy('name')
            ->get();

        // Which officers still hold a proposal in progress here: they cannot be
        // released until another officer is allotted.
        $busy = $officers->filter(fn (User $officer): bool => $this->pool->hasOpenWork($officer, $departmentId))->pluck('id')->all();

        $allotted = ProposalAllotment::query()->current()
            ->where('department_id', $departmentId)
            ->get(['ao_user_id', 'ah_user_id']);

        return view('department.users.index', [
            'department' => $admin->department,
            'officers' => $officers,
            'busy' => $busy,
            'counts' => [
                'ao' => $allotted->countBy('ao_user_id')->all(),
                'ah' => $allotted->countBy('ah_user_id')->all(),
            ],
        ]);
    }

    public function release(Request $request, User $officer): RedirectResponse
    {
        $admin = $this->admin($request);
        // A reason from the list, and the eSign (MoM of 2026-10-29).
        $data = $request->validate([
            'reason' => ['required', Rule::in(OfficerChangeReason::values())],
            'consent' => ['accepted'],
        ], ['consent.accepted' => __('esign.consent_required')]);

        try {
            $this->pool->assertReleasable($officer, $admin->department);
        } catch (AssignmentRefused $e) {
            return back()->withErrors(['release' => $e->getMessage()]);
        }

        if (! $this->gate->available()) {
            return back()->withErrors(['release' => __('esign.errors.unavailable')]);
        }

        return $this->gate->begin($request, $admin, ReleaseOfficer::class, ['officer' => $officer->id, 'reason' => $data['reason']]);
    }

    private function admin(Request $request): User
    {
        $admin = $request->user();
        abort_unless($admin instanceof User && $this->authority->isDepartmentAdmin($admin) && $admin->department !== null, 403);

        return $admin;
    }
}
