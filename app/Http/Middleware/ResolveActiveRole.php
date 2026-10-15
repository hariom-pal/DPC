<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\ActiveRoleService;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role as RoleModel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Narrow the signed-in account to the role it is **acting as** (change register of
 * 2026-08-26, item 9 as clarified).
 *
 * ### What it does, and why this is the whole mechanism
 *
 * One line does the work: the user's loaded `roles` relation is replaced with just
 * the active role. From that moment every existing authorisation check answers
 * about the active role and nothing else —
 *
 *  - `$user->hasRole(...)` and `hasAnyRole(...)`, which every policy in this
 *    application uses;
 *  - the `role:` route middleware, which reads the same relation;
 *  - `hasPermissionTo()`, which walks roles to permissions.
 *
 * **None of them had to be edited.** That is the point of doing it here rather than
 * by overriding `hasRole()` on the model or by revisiting sixteen policies: a
 * gating rule applied in one place cannot be forgotten in one of sixteen, and the
 * one that was forgotten would be a permission leak nobody was looking for.
 *
 * ### Ordering
 *
 * Must run **after** the session and authentication are available and **before**
 * any `role:` middleware. Appended to the `web` group, which puts it after
 * `StartSession` and `AuthenticateSession`; route middleware runs after the group,
 * so `role:` sees the constrained relation.
 *
 * ### What it deliberately does not touch
 *
 * `grantedRoleNames()` queries the pivot directly, so the role *switcher* can still
 * list what the account may switch to after this has run. And nothing here writes:
 * a request that never reaches a controller must not leave a session change behind,
 * so choosing a role is `RoleSwitchController`'s job and this only reads.
 */
class ResolveActiveRole
{
    public function __construct(private readonly ActiveRoleService $roles) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $active = $this->roles->resolve($user);

        if ($active === null) {
            /*
             | An account with no roles at all — a committee member recorded by the
             | DPC screen, which grants nothing (item 7). The relation is emptied
             | rather than left alone, so such an account cannot inherit whatever a
             | later `loadMissing()` would have fetched.
             */
            $user->setRelation('roles', new EloquentCollection);

            return $next($request);
        }

        $role = RoleModel::query()
            ->where('name', $active->value)
            ->where('guard_name', config('auth.defaults.guard', 'web'))
            ->first();

        if ($role !== null) {
            $user->setRelation('roles', new EloquentCollection([$role]));
        }

        // Read by the layout's switcher and by the dashboard, so neither has to
        // resolve it again and risk disagreeing with what was enforced.
        $request->attributes->set('active_role', $active);

        return $next($request);
    }
}
