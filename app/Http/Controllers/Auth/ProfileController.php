<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\SecurityLog;
use App\Domain\Department\Models\Department;
use App\Domain\UserManagement\Models\UserDepartmentAssignment;
use App\Domain\UserManagement\Services\DepartmentAssignmentService;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The AO's and AH's profile, shown at every sign-in (2026-10-28): their details,
 * read only, a declaration that they are correct, and the one thing they may
 * change themselves — their department. A change applies at once; the DPCs they
 * are allotted to stay theirs until the Department Admin changes the allotment.
 */
class ProfileController extends Controller
{
    public const SESSION_KEY = 'profile.confirmed';

    public function __construct(
        private readonly DepartmentAssignmentService $assignments,
        private readonly SecurityLog $log,
    ) {}

    /**
     * Whether this session must show the profile before anything else.
     */
    public static function mustConfirm(Request $request, User $user): bool
    {
        return ! $request->session()->get(self::SESSION_KEY, false) && self::isOfficer($user);
    }

    public function show(Request $request): View
    {
        $user = $this->officer($request);

        return view('auth.profile', [
            'user' => $user->loadMissing('designation'),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $this->officer($request);

        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => __('profile.confirm_required')]);

        if ((int) $data['department_id'] !== (int) $user->department_id) {
            $this->changeDepartment($user, Department::query()->findOrFail((int) $data['department_id']));
        }

        $request->session()->put(self::SESSION_KEY, true);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * The officer's own posting moves to the chosen department, in the role it
     * held (2026-10-28). Allotments are untouched.
     */
    private function changeDepartment(User $user, Department $department): void
    {
        $primary = UserDepartmentAssignment::query()->current()->whereNotNull('primary_flag')->where('user_id', $user->id)->first();
        $role = $primary !== null && in_array($primary->role, Role::departmentManageable(), true)
            ? $primary->role
            : (in_array(Role::AuthorizedOfficer->value, $user->grantedRoleNames(), true) ? Role::AuthorizedOfficer : Role::AdministrativeHead);

        $from = $user->department_id;
        $this->assignments->assign($user, $department, $role, remarks: 'Department changed by the officer on their profile.');
        $this->log->record('profile.department_changed', $user, context: ['from' => $from, 'to' => $department->id]);
    }

    private function officer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && self::isOfficer($user), 403);

        return $user;
    }

    private static function isOfficer(User $user): bool
    {
        return array_intersect($user->grantedRoleNames(), [Role::AuthorizedOfficer->value, Role::AdministrativeHead->value]) !== [];
    }
}
