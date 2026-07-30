<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Authentication\Models\User;
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
            'users' => User::query()->with(['roles', 'department'])->orderBy('name')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.form', $this->formData(new User));
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        /** @var array{name:string,email:string,mobile:string,designation_id:?int,role:string} $data */
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
        /** @var array{name:string,email:string,mobile:string,designation_id:?int,role:string} $data */
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
        $this->provisioning->resetPassword($user);

        return redirect()->route('admin.users.index')->with('status', 'A new temporary password has been issued.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(User $user): array
    {
        return [
            'user' => $user,
            'designations' => Designation::query()->where('status', MasterStatus::Active->value)->orderBy('name')->get(),
            'roles' => Role::provisionable(),
            'currentRole' => $user->exists ? $user->getRoleNames()->first() : null,
        ];
    }
}
