<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Authentication\Services\ActiveRoleService;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Switching the capacity a session is acting in (change register of 2026-08-26,
 * item 9 as clarified).
 *
 * > *Members will have their own dashboard as per the role assigned, so a member can
 * > switch their roles; every logged-in member can switch their role.*
 *
 * **A POST, not a GET**, and not negotiable. Switching capacity changes what the
 * session may do, so it is a state change: as a link it would be reachable by
 * anything that follows links — a prefetching browser, an e-mail scanner, an
 * `<img src>` on another site — and an officer could be moved into a different
 * capacity without acting. CSRF protection follows from the method.
 *
 * **Redirects to the dashboard rather than back.** The screen the user was on is
 * very often one the new capacity cannot open, and bouncing them to a 403 is a
 * worse answer than taking them somewhere that works. The dashboard is also where
 * the change is visible, since it is per-role.
 */
class RoleSwitchController extends Controller
{
    public function __construct(private readonly ActiveRoleService $roles) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $role = Role::tryFrom((string) $request->input('role'));

        if ($role === null) {
            return redirect()->route('dashboard')->withErrors([
                'role' => __('authui.role_switch.unknown'),
            ]);
        }

        try {
            $this->roles->switchTo($request->user(), $role);
        } catch (RuntimeException $e) {
            // The account does not hold that role. Reported rather than ignored, so
            // a user whose grant was withdrawn mid-session is told why.
            return redirect()->route('dashboard')->withErrors(['role' => $e->getMessage()]);
        }

        return redirect()->route('dashboard')->with('status', __('authui.role_switch.now_acting_as', [
            'role' => $role->label(),
        ]));
    }
}
