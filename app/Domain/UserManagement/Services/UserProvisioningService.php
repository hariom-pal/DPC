<?php

declare(strict_types=1);

namespace App\Domain\UserManagement\Services;

use App\Domain\Authentication\Events\CredentialsIssued;
use App\Domain\Authentication\Models\User;
use App\Domain\Shared\Services\ActivityTimelineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * CGPSC Admin provisioning of internal accounts (Chairman / Member / Admin).
 *
 * Provisioned accounts are created inactive-of-verification: the holder logs in
 * with the issued temporary password, completes email + mobile OTP verification
 * (reusing the M1 flow), then is forced to change the password. Credentials are
 * delivered via the queued CredentialsIssued pipeline.
 */
class UserProvisioningService
{
    public function __construct(private readonly ActivityTimelineService $timeline) {}

    /**
     * @param  array{name:string,email:string,mobile:string,designation_id:?int,role:string}  $data
     */
    public function provision(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $password = Str::password(12);

            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'mobile' => $data['mobile'],
                'designation_id' => $data['designation_id'] ?? null,
                'is_active' => true,
                'must_change_password' => true,
            ]);
            $user->password = Hash::make($password);
            $user->save();
            $user->syncRoles([$data['role']]);

            CredentialsIssued::dispatch($user, $password);
            $this->timeline->record('user.provisioned', $user, "Account provisioned with role {$data['role']}");

            return $user;
        });
    }

    /**
     * @param  array{name:string,email:string,mobile:string,designation_id:?int,role:string}  $data
     */
    public function update(User $user, array $data): User
    {
        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'mobile' => $data['mobile'],
            'designation_id' => $data['designation_id'] ?? null,
        ])->save();
        $user->syncRoles([$data['role']]);

        $this->timeline->record('user.updated', $user, 'Account details updated');

        return $user;
    }

    public function setActive(User $user, bool $active): void
    {
        $user->forceFill(['is_active' => $active])->save();
        $this->timeline->record($active ? 'user.activated' : 'user.deactivated', $user);
    }

    public function resetPassword(User $user): void
    {
        $password = Str::password(12);
        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => true,
        ])->save();

        CredentialsIssued::dispatch($user, $password);
        $this->timeline->record('user.password_reset', $user, 'Temporary password re-issued');
    }
}
