<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\QualificationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every screen that takes no parameters renders, for every role — final
 * verification.
 *
 * **What this catches that nothing else does.** The suite asserts behaviour screen
 * by screen, and a screen nobody wrote a test for can still be broken by a renamed
 * variable, a moved partial, a view composer that throws, or a route pointing at a
 * method that no longer exists. Those are exactly the failures a demo finds, in
 * front of the people the demo is for.
 *
 * **It asserts "not a server error", not "OK".** A 403 for the wrong role is correct
 * behaviour and is what the authorization tests are for; a redirect to a required
 * step is correct too. What is never correct is a 500. So this is a breadth check,
 * deliberately shallow — the complement to the depth the rest of the suite has, not
 * a substitute for it.
 *
 * **The route list is discovered, not written down.** A screen added later is
 * covered without anybody remembering to add it here, which is the only way a check
 * like this stays true.
 *
 * **One test rather than one per screen, on purpose.** A data provider would need
 * the route table before the application boots, and booting a second instance to
 * get it makes PHPUnit — rightly — call every resulting test risky. Collecting the
 * failures and reporting them together is also the more useful output: "these four
 * screens are broken" beats four separate red lines.
 */
class ScreenSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_parameterless_screen_returns_a_server_error_for_any_role(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        $this->seed(QualificationSeeder::class);

        $department = Department::query()->create([
            'code' => 'SE', 'name' => 'School Education', 'status' => 'active',
        ]);

        /** @var array<string, User|null> $identities */
        $identities = ['a guest' => null];

        foreach (Role::cases() as $role) {
            // The individual role holds no account in practice — Self-Inspection
            // identifies an officer by facts, not credentials — but the role exists,
            // so a user holding it must not be able to break a screen either.
            // A departmental role is held through an assignment, which is also
            // what grants it (change item 2); a Commission role is a plain grant
            // and holds no department. Both paths are exercised because both are
            // real, and a screen must not break for either.
            $user = $role->isDepartmental()
                ? User::factory()->assignedTo($department, $role)->create()
                : tap(User::factory()->create(), fn (User $u) => $u->assignRole($role->value));

            $identities[$role->value] = $user;
        }

        $screens = $this->screens();

        // A guard against the check passing because it found nothing to check —
        // the same reason `SchemaIdentifierLengthTest` counts what it inspected.
        $this->assertGreaterThan(
            40,
            count($screens),
            'Only '.count($screens).' screens were discovered; the route table is larger than that, '
            .'so this check is not looking at what it claims to look at.',
        );

        $failures = [];

        /*
         | Every screen, for every role, **in both locales** (change item 7).
         |
         | Hindi is not a cosmetic pass: a translation key that resolves to an
         | array, or a Hindi file that fails to parse, produces a 500 on exactly
         | the screens a Hindi-reading officer uses and on none of the English
         | ones. Rendering only in English would have declared the bilingual
         | portal green while half of it was broken.
         */
        foreach (['en', 'hi'] as $locale) {
            app()->setLocale($locale);
            session(['locale' => $locale]);

            foreach ($screens as $uri) {
                foreach ($identities as $label => $user) {
                    $request = $user === null ? $this : $this->actingAs($user);
                    $status = $request->get($uri)->getStatusCode();

                    if ($status >= 500) {
                        $failures[] = "GET {$uri} returned {$status} for {$label} in {$locale}";
                    }
                }
            }
        }

        app()->setLocale('en');

        $this->assertSame([], $failures, sprintf(
            '%d screen/role/locale combination(s) returned a server error. A 403 or a redirect would be '
            ."fine — a 500 is what a demo finds in front of the people the demo is for:\n  - %s",
            count($failures),
            implode("\n  - ", $failures),
        ));
    }

    /**
     * Every GET route with no path parameters, excluding the ones that are not
     * screens.
     *
     * @return list<string>
     */
    private function screens(): array
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            if (str_contains($uri, '{')) {
                continue;
            }

            // `health` and `up` probe infrastructure; `_ignition` and `_debugbar` are
            // development tooling; `lang/` is a redirect helper that needs a referer.
            // None is a screen.
            if (preg_match('#^(health|up|_|lang/)#', $uri) === 1) {
                continue;
            }

            $uris[$uri === '/' ? '/' : '/'.$uri] = true;
        }

        return array_keys($uris);
    }
}
