<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\Cadre;
use App\Domain\Master\Models\DpcType;
use App\Domain\PreDpc\Models\PreDpcProposal;
use App\Domain\SelfInspection\Models\SelfInspectionAccess;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\Feature\AuthorizationCoverageTest;
use Tests\TestCase;

/**
 * AD-8 — the UUID is the only public identifier.
 *
 * **This test exists because the decision was recorded, believed, documented as
 * done, and not true.** `DpcProposal` and `PreDpcProposal` — the two aggregates
 * a department actually uses — were reachable at `/dpc-proposals/4` and
 * `/pre-dpc/1` right through to the final phase, while thirteen lesser models
 * all bound on `uuid`. Two documents said otherwise. The suite passed either
 * way, because every test builds its URLs with `route()`, and `route()` happily
 * emits whichever key the model names.
 *
 * So the assertion here is deliberately not "this screen works". It is
 * **structural**: any model with a `uuid` column must bind on it. A future
 * aggregate that grows a UUID and forgets its route key fails on the day it is
 * written rather than in somebody's penetration test.
 *
 * @see AuthorizationCoverageTest — the same shape of check
 *      applied to role gating
 */
class PublicIdentifierTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The structural rule: **every model this application resolves from a URL,
     * and which has a UUID, must resolve on that UUID.**
     *
     * Scoped to route-bound models — read out of the router itself rather than
     * from a list — because that is where the exposure is. A model with a UUID
     * and no route (`SelfInspectionAccess`, today) is not leaking anything; if it
     * ever gains a route, it appears here on the same commit.
     */
    public function test_every_route_bound_model_with_a_uuid_binds_on_it(): void
    {
        $bound = $this->routeBoundModels();

        // If this ever comes back empty the test has stopped testing anything.
        $this->assertNotEmpty($bound, 'No route-bound models were discovered — the router reflection is not working.');

        $offenders = [];

        foreach ($bound as $class => $routes) {
            /** @var Model $model */
            $model = new $class;

            if (! Schema::hasTable($model->getTable()) || ! Schema::hasColumn($model->getTable(), 'uuid')) {
                continue;
            }

            if ($model->getRouteKeyName() !== 'uuid') {
                $offenders[] = $class.' binds on "'.$model->getRouteKeyName().'" — '.implode(', ', $routes);
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['These models carry a UUID but still expose their sequential id in a URL (AD-8):'],
            $offenders,
        )));
    }

    /**
     * A model with a UUID and no route is fine — but it is worth knowing which
     * ones those are, so that "it has no route" stays a deliberate answer rather
     * than something nobody checked.
     */
    public function test_a_model_with_a_uuid_and_no_route_is_recorded_as_such(): void
    {
        $bound = array_keys($this->routeBoundModels());

        $unrouted = array_values(array_filter($this->domainModels(), function (string $class) use ($bound): bool {
            /** @var Model $model */
            $model = new $class;

            return ! in_array($class, $bound, true)
                && Schema::hasTable($model->getTable())
                && Schema::hasColumn($model->getTable(), 'uuid');
        }));

        // Evidence rows and per-user access records are reached through their
        // parent aggregate, never addressed directly. If something arrives here
        // that ought to have a screen of its own, that is the thing to notice.
        $this->assertContains(SelfInspectionAccess::class, $unrouted);
    }

    /**
     * The two aggregates that were wrong, named explicitly.
     *
     * The structural test above catches a regression on either; naming them
     * keeps the specific finding legible to whoever reads this next.
     */
    public function test_the_two_proposal_aggregates_are_addressed_by_uuid(): void
    {
        $this->assertSame('uuid', (new DpcProposal)->getRouteKeyName());
        $this->assertSame('uuid', (new PreDpcProposal)->getRouteKeyName());
    }

    /**
     * A generated URL carries the UUID and no bare id.
     *
     * The regression this pins is quiet: `route()` reads `getRouteKeyName()`, so
     * every link in the application was emitting `/dpc-proposals/4` without a
     * single line of code saying so.
     */
    public function test_a_generated_proposal_url_carries_no_sequential_id(): void
    {
        [$officer, $proposal] = $this->aProposal();

        $url = route('dpc-proposals.show', $proposal);

        $this->assertTrue(Str::isUuid($proposal->uuid));
        $this->assertStringEndsWith('/'.$proposal->uuid, $url);
        $this->assertStringNotContainsString('/dpc-proposals/'.$proposal->id, $url);

        // And the URL the application generates is the one that works.
        $this->actingAs($officer)->get($url)->assertOk();
    }

    /**
     * **The part that was not merely cosmetic.**
     *
     * While the route key was `id`, MySQL resolved the binding by coercing the
     * URL segment to an integer. `/dpc-proposals/01a0282e-b21f-…/committee`
     * therefore returned **proposal 1** — a different record — with a 200
     * instead of a 404. A mistyped or guessed identifier silently produced
     * somebody else's DPC.
     *
     * SQLite does not coerce the same way, which is part of why nothing here
     * ever went red, so the assertion is on the binding contract rather than on
     * any one engine's coercion rules. It holds on both.
     */
    public function test_an_identifier_that_is_not_a_uuid_resolves_to_nothing(): void
    {
        [$officer, $proposal] = $this->aProposal();

        // The shape of URL that used to work: the record's own sequential id,
        // which is no longer a public identifier at all.
        $this->actingAs($officer)
            ->get('/dpc-proposals/'.$proposal->id)
            ->assertNotFound();

        // And a value whose leading characters are numeric — the exact case
        // MySQL coerced.
        $this->actingAs($officer)
            ->get('/dpc-proposals/'.$proposal->id.'abc-not-a-uuid')
            ->assertNotFound();

        // Same for Pre-DPC, the other aggregate that was addressed by id.
        $this->actingAs($officer)->get('/pre-dpc/1')->assertNotFound();
    }

    /**
     * A department, its Authorized Officer, and one draft proposal, built the
     * way the application builds them — through the route, not by mass
     * assignment, so the UUID comes from the same path production uses.
     *
     * @return array{0: User, 1: DpcProposal}
     */
    private function aProposal(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $department = Department::query()->create([
            'code' => 'PI', 'name' => 'Public Instruction Department', 'status' => 'active',
        ]);

        $officer = User::factory()->create(['department_id' => $department->id]);
        $officer->assignRole(Role::AuthorizedOfficer->value);

        $from = Cadre::query()->create([
            'department_id' => $department->id, 'name' => 'Lecturer', 'class_level' => 'II', 'status' => 'active',
        ]);
        $to = Cadre::query()->create([
            'department_id' => $department->id, 'name' => 'Principal', 'class_level' => 'I', 'status' => 'active',
        ]);

        $this->actingAs($officer)->post(route('dpc-proposals.store'), [
            'dpc_type_id' => DpcType::query()->where('code', 'fresh')->firstOrFail()->id,
            'post_to_cadre_id' => $to->id,
            'post_from_cadre_id' => $from->id,
            'proposal_date' => '2026-06-25',
        ])->assertRedirect();

        return [$officer, DpcProposal::query()->latest('id')->firstOrFail()];
    }

    /**
     * Every Eloquent model the router resolves from a URL segment, and the
     * routes that resolve it.
     *
     * Read out of the router's own signature reflection — the same mechanism
     * Laravel uses to do the binding — so it cannot drift from reality the way a
     * hand-kept list can.
     *
     * @return array<class-string<Model>, list<string>>
     */
    private function routeBoundModels(): array
    {
        $bound = [];

        foreach (RouteFacade::getRoutes() as $route) {
            // Only parameters that actually appear in the URI are bound from it;
            // a controller may also type-hint a Request or a Service.
            $names = $route->parameterNames();

            if ($names === []) {
                continue;
            }

            foreach ($route->signatureParameters(UrlRoutable::class) as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof \ReflectionNamedType) {
                    continue;
                }

                /** @var class-string $class */
                $class = $type->getName();

                if (! is_subclass_of($class, Model::class)) {
                    continue;
                }

                $bound[$class][] = $route->uri();
            }
        }

        foreach ($bound as $class => $routes) {
            $bound[$class] = array_values(array_unique($routes));
        }

        return $bound;
    }

    /**
     * Every concrete Eloquent model under `app/Domain`.
     *
     * Discovered from the filesystem rather than listed, because a list is a
     * thing somebody forgets to add to — which is how this defect survived
     * eleven milestones.
     *
     * @return list<class-string<Model>>
     */
    private function domainModels(): array
    {
        $classes = [];

        foreach (Finder::create()->files()->in(app_path('Domain'))->name('*.php')->path('Models') as $file) {
            $class = 'App\\Domain\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                (string) $file->getRelativePathname(),
            );

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            // Abstract bases (the per-year flag tables share one) cannot be
            // instantiated and have no routes of their own.
            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $classes[] = $class;
        }

        // The one cross-cutting model that does not live under a Models/ folder.
        if (class_exists(WorkflowTransition::class)) {
            $classes[] = WorkflowTransition::class;
        }

        sort($classes);

        $this->assertNotEmpty($classes, 'No domain models were discovered — the finder is looking in the wrong place.');

        return $classes;
    }
}
