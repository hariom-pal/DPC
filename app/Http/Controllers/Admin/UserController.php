<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\Designation;
use App\Domain\UserManagement\Requests\StoreUserRequest;
use App\Domain\UserManagement\Requests\UpdateUserRequest;
use App\Domain\UserManagement\Services\UserProvisioningService;
use App\Enums\MasterStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class UserController extends Controller
{
    public function __construct(private readonly UserProvisioningService $provisioning) {}

    public function index(): View
    {
        return view('admin.users.index', [
            // `activeAssignment.department` rather than `department`: since
            // change item 2 the department is read off the live assignment, so
            // eager-loading the old relation name would be eager-loading nothing
            // and every row would issue its own query.
            'users' => User::query()
                ->with(['roles', 'activeAssignment.department'])
                ->orderBy('name')
                ->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.form', $this->formData(new User));
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        /** @var array{name:string,email:string,mobile:string,designation_id:?int,role:string,department_id?:int|null} $data */
        $data = $request->validated();
        $this->provisioning->provision($data);

        return redirect()->route('admin.users.index')->with('status', 'Account provisioned. Credentials have been issued.');
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', $this->formData($user));
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        /** @var array{name:string,email:string,mobile:string,designation_id:?int,role:string,department_id?:int|null} $data */
        $data = $request->validated();
        $this->provisioning->update($user, $data);

        return redirect()->route('admin.users.index')->with('status', 'Account updated.');
    }

    public function toggleActive(User $user): RedirectResponse
    {
        $this->provisioning->setActive($user, ! $user->is_active);

        return redirect()->route('admin.users.index')
            ->with('status', $user->is_active ? 'Account deactivated.' : 'Account activated.');
    }

    public function resetPassword(User $user): RedirectResponse
    {
        $password = $this->provisioning->resetPassword($user);

        /*
         | **Shown once, on the screen the Administrator is standing at.**
         |
         | It is also sent to the user by SMS and e-mail, and until 2026-09-07 that
         | was the only copy — which on a deployment whose gateways are in log mode
         | means nobody could read it. Pressing Reset Password locked the account
         | out instead of recovering it, and there was no second attempt that would
         | help, because each one issues a different password.
         |
         | Flashed rather than rendered inline because this redirects, and not
         | persisted anywhere: it is a temporary password, the user must change it on
         | first use, and a copy left in a database or a log is a copy that outlives
         | its purpose.
         */
        return redirect()
            ->route('admin.users.index')
            ->with('status', __('admin.users.password_reset'))
            ->with('issuedPassword', ['name' => $user->name, 'password' => $password]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(User $user): array
    {
        return [
            'user' => $user,
            'designations' => Designation::query()->where('status', MasterStatus::Active->value)->orderBy('name')->get(),
            'departments' => Department::query()->orderBy('name')->get(),
            'roles' => Role::provisionable(),
            'departmentalRoles' => Role::departmentalValues(),
            'currentRole' => $user->exists ? $user->getRoleNames()->first() : null,
            'currentDepartmentId' => $user->exists ? $user->department_id : null,
        ];
    }
}
