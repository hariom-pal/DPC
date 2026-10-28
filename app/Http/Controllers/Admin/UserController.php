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
use App\Http\Controllers\Concerns\ShowsIssuedPassword;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UserController extends Controller
{
    use ShowsIssuedPassword;

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

    /**
     * Provision an account and show its temporary password once (business decision,
     * 2026-10-26): never mailed, never flashed.
     */
    public function store(StoreUserRequest $request): Response
    {
        /** @var array{name:string,email:string,mobile:string,designation_id:?int,role:string,department_id?:int|null} $data */
        $data = $request->validated();
        $issued = $this->provisioning->provision($data, $request->user());

        return $this->showIssuedPassword($issued->user, $issued->temporaryPassword, 'created', 'layouts.admin', route('admin.users.index'));
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', $this->formData($user));
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        /** @var array{name:string,email:string,mobile:string,designation_id:?int,role:string,department_id?:int|null} $data */
        $data = $request->validated();
        $this->provisioning->update($user, $data, $request->user());

        return redirect()->route('admin.users.index')->with('status', 'Account updated.');
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $this->provisioning->setActive($user, ! $user->is_active, $request->user());

        return redirect()->route('admin.users.index')
            ->with('status', $user->is_active ? 'Account deactivated.' : 'Account activated.');
    }

    /**
     * Issue a NEW temporary password and show it once (business decision,
     * 2026-10-26). The previous password stops working; it is never shown, because
     * it is never stored. Rate limited per administrator and per account.
     */
    public function resetPassword(Request $request, User $user): Response
    {
        $password = $this->provisioning->resetPassword($user, $request->user());

        return $this->showIssuedPassword($user, $password, 'reset', 'layouts.admin', route('admin.users.index'));
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
