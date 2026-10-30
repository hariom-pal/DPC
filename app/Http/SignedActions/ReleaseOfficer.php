<?php

declare(strict_types=1);

namespace App\Http\SignedActions;

use App\Domain\Authentication\Models\User;
use App\Domain\Signature\Contracts\SignedAction;
use App\Domain\UserManagement\Exceptions\AssignmentRefused;
use App\Domain\UserManagement\Services\DepartmentUserAuthority;
use App\Domain\UserManagement\Services\OfficerPool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Release an officer from the department, eSigned by the Department Admin (MoM
 * of 2026-10-29). Payload: officer (user id), reason (an OfficerChangeReason).
 */
class ReleaseOfficer implements SignedAction
{
    public function __construct(
        private readonly DepartmentUserAuthority $authority,
        private readonly OfficerPool $pool,
    ) {}

    public function perform(Request $request, User $signer, array $payload): RedirectResponse
    {
        abort_unless($this->authority->isDepartmentAdmin($signer) && $signer->department !== null, 403);
        $officer = User::query()->findOrFail((int) $payload['officer']);

        try {
            $this->pool->release($officer, $signer->department, $signer, (string) $payload['reason'], signed: true);
        } catch (AssignmentRefused $e) {
            return redirect()->route('department.users.index')->withErrors(['release' => $e->getMessage()]);
        }

        return redirect()->route('department.users.index')->with('status', __('department.users.release.done', ['name' => $officer->name]));
    }

    public function abandon(array $payload): RedirectResponse
    {
        return redirect()->route('department.users.index');
    }
}
