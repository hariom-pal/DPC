<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Authentication\Models\User;
use App\Domain\UserManagement\Services\OfficerPool;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The AO's and AH's profile, shown at every sign-in (2026-10-28): their details,
 * read only, and a declaration that they are correct. Since 2026-10-29 the
 * department is read only too: a transfer is the Department Admin's release and
 * the new department's choice ({@see OfficerPool}).
 */
class ProfileController extends Controller
{
    public const SESSION_KEY = 'profile.confirmed';

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
            'user' => $user->loadMissing(['designation', 'activeAssignment.department']),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $this->officer($request);

        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => __('profile.confirm_required')]);

        $request->session()->put(self::SESSION_KEY, true);

        return redirect()->intended(route('dashboard'));
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
