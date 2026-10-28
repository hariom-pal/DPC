<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Authentication\Services\ActiveRoleService;
use App\Domain\Authentication\Services\SecurityLog;
use App\Domain\UserManagement\Models\UserDepartmentAssignment;
use App\Domain\UserManagement\Services\ActingAssignmentService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switch the department (and with it the role) an officer acts in, among their
 * current assignments (Phase 6, 2026-10-27). Re-checked on every request by
 * ResolveActiveRole; this only records the choice.
 */
class ActingDepartmentController extends Controller
{
    public function __construct(
        private readonly ActingAssignmentService $acting,
        private readonly ActiveRoleService $roles,
        private readonly SecurityLog $log,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $assignment = UserDepartmentAssignment::query()->find($request->integer('assignment_id'));

        abort_if($user === null || $assignment === null || ! $this->acting->switchTo($user, $assignment), 403);

        $this->roles->switchTo($user, $assignment->role);
        $this->log->record('assignment.switched', $user, $request, ['department_id' => $assignment->department_id, 'role' => $assignment->role->value]);

        return redirect()->route('dashboard');
    }
}
