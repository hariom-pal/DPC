<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\UserManagement\Requests\AssignUserRequest;
use App\Domain\UserManagement\Services\DepartmentAssignmentService;
use App\Enums\DepartmentStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

/**
 * Role Assignments — the CGPSC Administrator's transfer screen (change items 1
 * and 2).
 *
 * **A separate screen from Edit Account on purpose.** Editing an account changes
 * who the officer *is* — their name, their email, their mobile. Transferring them
 * changes where they work, which is a dated event with a history, and the two
 * being one form is how a typo in an email address ends up recorded as a
 * transfer. This screen shows the history and appends to it; it never edits a
 * past row, because a past posting is a fact about a period that has closed.
 *
 * There is no destroy for an individual assignment. Releasing an officer retires
 * the current posting and keeps it — see `DepartmentAssignmentService::release()`
 * for why the row survives and what the difference is between releasing an
 * officer and deactivating their account.
 */
class UserAssignmentController extends Controller
{
    public function __construct(private readonly DepartmentAssignmentService $assignments) {}

    public function index(User $user): View
    {
        return view('admin.users.assignments', [
            'user' => $user,
            'assignments' => $user->assignments()->with('department')->get(),
            'departments' => Department::query()
                // Only a registered department can receive a posting: an officer
                // posted to a department that has not completed S6 registration
                // would hold a role over a department with no masters, no posts
                // and nothing to do.
                ->where('status', DepartmentStatus::Registered->value)
                ->orderBy('name')
                ->get(),
            'roles' => Role::departmental(),
        ]);
    }

    public function store(AssignUserRequest $request, User $user): RedirectResponse
    {
        $department = Department::query()->findOrFail($request->integer('department_id'));

        $effectiveFrom = $request->filled('effective_from')
            ? Carbon::parse((string) $request->input('effective_from'))
            : null;

        $this->assignments->assign(
            $user,
            $department,
            Role::from((string) $request->input('role')),
            $effectiveFrom,
            $request->input('remarks') === null ? null : (string) $request->input('remarks'),
        );

        return redirect()->route('admin.users.assignments.index', $user)
            ->with('status', 'Assignment recorded. The previous posting has been closed and kept.');
    }

    public function release(User $user): RedirectResponse
    {
        $released = $this->assignments->release($user, remarks: 'Released by the CGPSC Administrator.');

        return redirect()->route('admin.users.assignments.index', $user)->with(
            'status',
            $released === null
                ? 'This account held no current posting, so nothing changed.'
                : 'Posting closed. The account keeps its login and its history, and now holds no department role.',
        );
    }
}
