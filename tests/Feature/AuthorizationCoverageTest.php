<?php

declare(strict_types=1);

namespace Tests\Feature;

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
