<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Shared\Services\ActivityTimelineService;
use App\Enums\Role;
use Illuminate\Contracts\Session\Session;
use RuntimeException;

/**
 * Which role a user is **acting as** for this session (change register of
 * 2026-08-26, item 9 as clarified).
 *
 * > *A member is also a user — it can work as AO or AH too, it can be Chairman or
 * > anything. Members will have their own dashboard as per the role assigned, so a
 * > member can switch their roles; every logged-in member can switch their role.*
 *
 * ### Why the active role gates rather than decorates
 *
 * One identity may hold several roles at once. That leaves a question no previous
 * version of this portal had to answer: when an officer who is both an Authorized
 * Officer and a Commission Member presses *Approve*, **in which capacity** did they
 * approve? A portal that simply unions their permissions cannot say, and a
 * promotion record that cannot say who acted in what capacity is not a record.
 *
 * So the active role is the acting capacity, and it *narrows* what the session can
 * do. An officer acting as a Member cannot approve as an Authorized Officer without
 * switching first — and the switch is itself an event on the timeline.
 *
 * ### How the narrowing is applied
 *
 * Not by overriding `hasRole()`. `ResolveActiveRole` constrains the user's loaded
 * `roles` relation to the active role, and every existing check — every policy,
 * every `role:` middleware, every `hasPermissionTo()` — then answers about the
 * active role without being edited. That is the whole reason for doing it this way:
 * the alternative was touching sixteen policies and trusting that none was missed.
 *
 * {@see User::grantedRoles()} is how a caller asks the *other* question — what this
 * account may switch to — and it queries rather than reading the constrained
 * relation.
 *
 * ### There is always an active role
 *
 * A total function, deliberately. If the session names a role the user does not
 * hold — a stale session after an Administrator withdrew a grant — it is ignored
 * and the precedence default applies. Leaving the active role null and falling back
 * to the union would mean a stale session quietly regained every permission the
 * user had ever held, which is the one outcome this feature exists to prevent.
 */
class ActiveRoleService
{
    public const SESSION_KEY = 'active_role';

    /**
     * Which role a user lands in when they have not chosen.
     *
     * **Least privilege first is wrong here**, and it is worth saying why. The
     * default is the role the account most plausibly signed in to use, because an
     * Administrator dropped into a Promotion Candidate's read-only screen would
     * conclude the portal was broken. Ordering by *authority* instead would default
     * an ordinary officer into Administrator, which is worse. So this is ordered by
     * how specific the role is to the work the account exists to do: departmental
     * duties first, then the Commission's, then the two read-only capacities.
     *
     * @var list<Role>
     */
    private const PRECEDENCE = [
        Role::AuthorizedOfficer,
        Role::AdministrativeHead,
        Role::Chairman,
        Role::Member,
        Role::CgpscStaff,
        Role::Admin,
        Role::PromotionCandidate,
        Role::Individual,
    ];

    public function __construct(
        private readonly Session $session,
        private readonly ActivityTimelineService $timeline,
    ) {}

    /**
     * The role this session is acting as, or null if the account holds none.
     */
    public function resolve(User $user): ?Role
    {
        $granted = $this->available($user);

        if ($granted === []) {
            return null;
        }

        $chosen = Role::tryFrom((string) $this->session->get(self::SESSION_KEY));

        if ($chosen !== null && in_array($chosen, $granted, true)) {
            return $chosen;
        }

        return $this->defaultFor($granted);
    }

    /**
     * Every role this account may act as.
     *
     * @return list<Role>
     */
    public function available(User $user): array
    {
        return array_values(array_filter(array_map(
            static fn (string $name): ?Role => Role::tryFrom($name),
            $user->grantedRoleNames(),
        )));
    }

    /**
     * Start acting as a role.
     *
     * **Refuses a role the account does not hold**, rather than silently ignoring
     * it. A rejected switch is a request to hold a permission the account was never
     * granted, and answering it with a shrug would leave the user believing they
     * had switched.
     */
    public function switchTo(User $user, Role $role): void
    {
        if (! in_array($role, $this->available($user), true)) {
            throw new RuntimeException(sprintf(
                'This account does not hold the %s role, so it cannot act as one.',
                $role->label(),
            ));
        }

        $previous = $this->resolve($user);

        $this->session->put(self::SESSION_KEY, $role->value);

        if ($previous === $role) {
            return;
        }

        /*
         | Recorded, because the capacity an action was taken in is now a fact the
         | portal asserts. If a proposal was approved at 11:04 by an account that
         | switched into Administrative Head at 11:03, the timeline has to be able
         | to say so.
         */
        $this->timeline->record(
            'user.role_switched',
            $user,
            sprintf('%s is now acting as %s', $user->name, $role->label()),
            ['from' => $previous?->value, 'to' => $role->value],
            $user,
        );
    }

    /**
     * Forget the choice, so the next request resolves the default again.
     */
    public function clear(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }

    /**
     * @param  list<Role>  $granted
     */
    private function defaultFor(array $granted): Role
    {
        foreach (self::PRECEDENCE as $role) {
            if (in_array($role, $granted, true)) {
                return $role;
            }
        }

        // A role outside the precedence list — impossible today, and returning the
        // first held role is better than returning null and re-opening the union.
        return $granted[0];
    }
}
