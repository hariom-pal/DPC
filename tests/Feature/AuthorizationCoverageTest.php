<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureCanWorkAnAllotment;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every authenticated route is either role-gated or on a deliberate allowlist.
 *
 * **The failure this prevents.** A route added inside the authenticated group but
 * outside a `role:` middleware group is reachable by every signed-in user. That is
 * correct for a handful of screens and wrong for everything else — and it is
 * invisible, because the route works, the tests for its happy path pass, and
 * nothing anywhere says who was supposed to be able to reach it.
 *
 * So the exceptions are enumerated here with a reason each. **Adding a route
 * without a role gate now fails this test**, and the fix is either to gate it or to
 * add it below with an argument. Either is fine; silently doing neither is not.
 *
 * **This is a structural check, not a substitute for the authorization tests.**
 * `RoleBasedUiVisibilityTest` and the policy tests assert who may actually do what.
 * This asserts only that nobody forgot to decide.
 */
class AuthorizationCoverageTest extends TestCase
{
    /**
     * Authenticated routes that are deliberately open to every role.
     *
     * @var array<string, string> route name => why
     */
    private const EVERY_ROLE = [
        'dashboard' => 'Every actor has a dashboard; each card is scoped by DashboardSummaryService.',
        'logout' => 'Everybody may sign out.',
        'password.change.show' => 'A first-login password change must be reachable before anything else.',
        'password.change.update' => 'As above.',

        // M11.
        'notifications.index' => 'Every actor has a feed. It contains only what was addressed to them, '
            .'and ownership is enforced in NotificationCentre rather than by route scoping.',
        'notifications.read' => 'Ownership is checked in NotificationCentre::markRead(); reading another '
            .'actor\'s notification would disclose that a DPC exists and what stage it is at.',
        'notifications.read-all' => 'Scoped to the signed-in user by the service.',
        'reports.index' => 'Every role has a reports screen. DpcReportService::scopeFor() decides what '
            .'they see — a department its own DPCs, the Commission all of them.',
        'reports.export' => 'Same scope as the screen it exports.',

        /*
         | The sitting, from a committee member's side (business instruction,
         | 2026-09-07).
         |
         | **Ungated on purpose, and it is the one case where a role gate would be
         | the wrong control.** These screens are for a DPC committee member, and
         | item 7 of the register forbids granting a Commission role for sitting on
         | one committee — *"do NOT assign any permanent Commission Member role to a
         | user"* — because it is not an office one holds. A `role:` middleware here
         | would close the screens to exactly the people they were built for, which
         | is what `/cgpsc` already does to them.
         |
         | The control is a **seat**: every action calls
         | `CommitteeSittingService::isSeatedOn()` and aborts 403 for anybody not
         | serving on *that* proposal's committee — which is narrower than any role
         | gate, because it is per proposal rather than per account.
         | `CommitteeSittingTest` pins both directions.
         */
        'sitting.index' => 'Lists only the DPCs the signed-in account holds a serving committee seat on; '
            .'an account on none sees an empty list rather than a 403.',
        'sitting.show' => 'Guarded by CommitteeSittingService::isSeatedOn() on this proposal.',
        'sitting.candidate' => 'As above, and the row must belong to the proposal or it is a 404.',
        'sitting.call' => 'As above, and additionally refused outside the scheduled sitting window.',
        'sitting.conclude' => 'As above, and refused unless the candidate was called first.',

        // Change register of 2026-08-26, item 9 as clarified.
        'acting-as' => 'Every authenticated account may change the capacity it is acting in, so a role '
            .'gate here would let the capacity you are currently in decide whether you could leave it. '
            .'What an account may switch *to* is the set it has been granted, and '
            .'ActiveRoleService::switchTo() refuses anything else — so this route can only ever narrow '
            .'or restore permissions the account already holds, never add one.',
        // 2026-10-28: the eSign step; every action is authorised by the proposal's policy.
        'esign.show' => 'EsignController::proposal() authorises the proposal\'s own submit/approve ability.',
        'esign.start' => 'As above; the workflow service re-checks the current allotment and the eSign name.',
        'esign.simulator' => 'Non-production with the mock provider only; bound to this session\'s own pending eSign.',
        'esign.simulator.submit' => 'As above; the pending eSign is single-use and expires in ten minutes.',
        // 2026-10-28: the AO's / AH's own profile; ProfileController refuses anyone else.
        'profile.show' => 'An AO or AH sees only their own profile; ProfileController::officer() refuses every other account.',
        'profile.confirm' => 'As above; the only change it makes is the officer\'s own department.',
        // Phase 6, 2026-10-27.
        'acting-department' => 'The department an officer with several assignments acts in. ActingAssignmentService::switchTo() '
            .'accepts only one of the account\'s own current assignments, and ResolveActiveRole re-checks it against the '
            .'database on every request — so it can only choose among access already approved, never add any.',
    ];

    public function test_every_authenticated_route_is_role_gated_or_deliberately_open(): void
    {
        $ungated = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            $authenticated = false;
            $roleGated = false;

            foreach ($middleware as $layer) {
                if (! is_string($layer)) {
                    continue;
                }

                if ($layer === 'auth' || str_starts_with($layer, 'auth:')) {
                    $authenticated = true;
                }

                if (str_starts_with($layer, 'role')) {
                    $roleGated = true;
                }

                /*
                 | A gate that is not a role, and has to count as one here.
                 |
                 | The allottee's four actions cannot be described by a role at all:
                 | a DPC is allotted to a member of its own committee, and committee
                 | membership is deliberately not an office (2026-09-07). So those
                 | routes carry `EnsureCanWorkAnAllotment` instead, which admits the
                 | Chairman, a Member, or somebody holding a live allotment — and
                 | refuses everybody else. Counting them as ungated would be wrong,
                 | and allowlisting them as "reachable by every role" would be worse:
                 | it would state something untrue about them.
                 */
                if ($layer === EnsureCanWorkAnAllotment::class) {
                    $roleGated = true;
                }
            }

            if (! $authenticated) {
                continue;
            }

            $checked++;

            if ($roleGated) {
                continue;
            }

            $name = (string) $route->getName();

            if (! array_key_exists($name, self::EVERY_ROLE)) {
                $ungated[] = sprintf(
                    '%s %s (%s)',
                    implode('|', $route->methods()),
                    $route->uri(),
                    $name === '' ? 'unnamed' : $name,
                );
            }
        }

        // A guard against passing because nothing was inspected.
        $this->assertGreaterThan(
            50,
            $checked,
            "Only {$checked} authenticated routes were inspected; there are more than that, so this "
            .'check is not looking at what it claims to look at.',
        );

        $this->assertSame([], $ungated, sprintf(
            '%d authenticated route(s) have no role gate and are not on the allowlist. Gate them, or '
            ."add them to AuthorizationCoverageTest::EVERY_ROLE with a reason:\n  - %s",
            count($ungated),
            implode("\n  - ", $ungated),
        ));
    }

    /**
     * The allowlist stays honest: an entry for a route that no longer exists is a
     * stale exemption, and the next route to reuse that name inherits it silently.
     */
    public function test_the_allowlist_contains_no_stale_entries(): void
    {
        $stale = [];

        foreach (array_keys(self::EVERY_ROLE) as $name) {
            if (Route::getRoutes()->getByName($name) === null) {
                $stale[] = $name;
            }
        }

        $this->assertSame([], $stale, 'These allowlist entries name routes that no longer exist: '
            .implode(', ', $stale));
    }
}
