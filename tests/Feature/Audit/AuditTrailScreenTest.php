<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domain\Audit\Services\AuditTrailService;
use App\Domain\Authentication\Models\User;
use App\Domain\Shared\Models\ActivityTimeline;
use App\Domain\Shared\Workflow\WorkflowTransition;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * The audit-trail screen — M11.
 *
 * **Why it exists, from the enhancement backlog:** the audits and the Activity
 * Timeline have been written since M1 and were readable only from the database —
 * *"the first time anyone wants them is a contested promotion, which is the worst
 * time to discover there is no screen."*
 *
 * Three things are pinned here, and the third is the one that matters most:
 *
 * 1. All **three** records are readable, and are labelled as the different things
 *    they are rather than flattened into one "log".
 * 2. The filters filter, including across a union of three differently-shaped
 *    tables — where a filter applied to the wrong column would return a
 *    confident, wrong answer.
 * 3. **It is read-only.** No route creates, edits, deletes or purges an entry, and
 *    a test says so — an audit record editable from the application that writes it
 *    is not an audit record.
 */
class AuditTrailScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['name' => 'CGPSC Administrator']);
        $this->admin->assignRole(Role::Admin->value);

        $this->officer = User::factory()->create(['name' => 'Shri Anil Kumar Verma']);
        $this->officer->assignRole(Role::AuthorizedOfficer->value);

        $this->seedTrail();
    }

    // ------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------

    public function test_the_trail_is_the_admins_alone(): void
    {
        $this->actingAs($this->officer)->get(route('admin.audit-trail.index'))->assertForbidden();
        // A guest is refused by the role middleware before the auth redirect, so
        // this asserts the refusal rather than the particular shape of it.
        $this->get(route('admin.audit-trail.index'))->assertForbidden();

        $this->actingAs($this->admin)->get(route('admin.audit-trail.index'))->assertOk();
    }

    /**
     * There is no write route, and this is the test that keeps it that way.
     *
     * A future convenience — "purge entries older than a year", "correct a typo in
     * a remark" — would be the end of the record's standing, so the absence is
     * asserted rather than merely intended.
     */
    public function test_no_route_can_write_to_the_trail(): void
    {
        $router = app('router');

        foreach (['store', 'update', 'destroy', 'purge', 'delete', 'edit', 'create'] as $verb) {
            $this->assertFalse(
                $router->has("admin.audit-trail.{$verb}"),
                "admin.audit-trail.{$verb} exists; the audit trail must stay read-only",
            );
        }

        // And the routes that do exist accept GET only.
        foreach ($router->getRoutes()->getRoutesByName() as $name => $route) {
            if (! str_starts_with((string) $name, 'admin.audit-trail.')) {
                continue;
            }

            $this->assertSame(['GET', 'HEAD'], $route->methods(), "{$name} must be read-only");
        }
    }

    // ------------------------------------------------------------------
    // All three records
    // ------------------------------------------------------------------

    public function test_all_three_records_appear_and_are_labelled_as_different_records(): void
    {
        $this->actingAs($this->admin)->get(route('admin.audit-trail.index'))
            ->assertOk()
            ->assertSee('Workflow ledger')
            ->assertSee('Activity timeline')
            ->assertSee('Data changes')
            // One entry from each.
            ->assertSee('pre_dpc: draft → submitted')
            ->assertSee('The proposal was forwarded to the Commission')
            ->assertSee('updated');
    }

    public function test_the_counts_show_whether_each_record_is_being_written(): void
    {
        $counts = app(AuditTrailService::class)->counts();

        $this->assertSame(1, $counts[AuditTrailService::SOURCE_WORKFLOW]);
        $this->assertSame(2, $counts[AuditTrailService::SOURCE_TIMELINE]);

        // Not a literal: `users` is itself auditable, so creating the two fixture
        // accounts writes audit rows of its own. Asserting a fixed number here
        // would be asserting a side effect of the fixture, which is how a test
        // starts failing for a reason unrelated to what it checks.
        $this->assertSame(Audit::query()->count(), $counts[AuditTrailService::SOURCE_DATA]);
        $this->assertGreaterThanOrEqual(1, $counts[AuditTrailService::SOURCE_DATA]);
    }

    /** A record with nothing in it is called out, not left looking normal. */
    public function test_an_empty_record_is_flagged(): void
    {
        // Including the audits `users` wrote while the fixture was built.
        Audit::query()->forceDelete();

        $this->actingAs($this->admin)->get(route('admin.audit-trail.index'))
            ->assertOk()
            ->assertSee('No entries');
    }

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    public function test_filtering_by_record_narrows_to_that_record(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.audit-trail.index', ['source' => 'workflow']))
            ->assertOk()
            ->assertSee('pre_dpc: draft → submitted')
            ->assertDontSee('The proposal was forwarded to the Commission');
    }

    /**
     * An unrecognised record yields nothing.
     *
     * The opposite mistake — falling back to "all" — would show the whole trail to
     * somebody who asked for one slice of it.
     */
    public function test_an_unrecognised_record_yields_nothing_rather_than_everything(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.audit-trail.index', ['source' => 'not-a-record']))
            ->assertOk()
            ->assertSee('Nothing recorded matches these filters');
    }

    public function test_filtering_by_event_actor_and_subject(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.audit-trail.index', ['event' => 'submit']))
            ->assertOk()
            ->assertSee('pre_dpc: draft → submitted')
            ->assertDontSee('documents_published');

        // The actor filter reaches the workflow ledger through `users` and the
        // timeline through its own actor snapshot — two different columns, one
        // filter, which is where a shared-column assumption would go wrong.
        $this->actingAs($this->admin)
            ->get(route('admin.audit-trail.index', ['actor' => 'Anil']))
            ->assertOk()
            ->assertSee('pre_dpc: draft → submitted')
            ->assertSee('The proposal was forwarded to the Commission');

        $this->actingAs($this->admin)
            ->get(route('admin.audit-trail.index', ['actor' => 'Nobody At All']))
            ->assertOk()
            ->assertSee('Nothing recorded matches these filters');
    }

    public function test_filtering_by_date_range(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.audit-trail.index', ['from' => '2026-08-01', 'to' => '2026-08-02']))
            ->assertOk()
            ->assertSee('pre_dpc: draft → submitted')
            ->assertDontSee('documents_published');
    }

    public function test_filtering_by_subject_gathers_every_record_about_one_thing(): void
    {
        // The question a contested promotion actually asks.
        $this->actingAs($this->admin)
            ->get(route('admin.audit-trail.index', [
                'subject_type' => 'App\\Domain\\Dpc\\Models\\DpcProposal',
                'subject_id' => 7,
            ]))
            ->assertOk()
            ->assertSee('pre_dpc: draft → submitted')
            ->assertSee('The proposal was forwarded to the Commission')
            ->assertSee('updated')
            ->assertDontSee('circulation_note.signed');
    }

    // ------------------------------------------------------------------
    // The entry itself
    // ------------------------------------------------------------------

    public function test_a_workflow_entry_shows_its_remarks_signature_and_ip(): void
    {
        $transition = WorkflowTransition::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.audit-trail.show', ['workflow', $transition->id]))
            ->assertOk()
            ->assertSee('Returned for a missing CR year')
            ->assertSee('203.0.113.9')
            // A transition without a signature says so, rather than showing a gap.
            ->assertSee('carried no signature');
    }

    /**
     * The timeline's actor snapshot is what it exists for: who the actor was
     * *then*, not who that user is now.
     */
    public function test_a_timeline_entry_shows_the_actor_as_recorded_then(): void
    {
        $entry = ActivityTimeline::query()->where('event', 'dpc_proposal.forwarded')->firstOrFail();

        $this->officer->forceFill(['name' => 'Renamed Since'])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.audit-trail.show', ['timeline', $entry->id]))
            ->assertOk()
            ->assertSee('Actor (as recorded then)')
            ->assertSee('Shri Anil Kumar Verma')
            ->assertDontSee('Renamed Since');
    }

    public function test_a_data_entry_shows_the_old_value_beside_the_new(): void
    {
        // The proposal audit specifically. `firstOrFail()` would pick up a `users`
        // audit written while the fixture accounts were created.
        $audit = Audit::query()
            ->where('auditable_type', 'App\\Domain\\Dpc\\Models\\DpcProposal')
            ->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.audit-trail.show', ['data', $audit->id]))
            ->assertOk()
            ->assertSee('Values changed')
            ->assertSee('submitted')
            ->assertSee('approved');
    }

    public function test_an_unknown_entry_is_a_404(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.audit-trail.show', ['workflow', 99999]))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('admin.audit-trail.show', ['not-a-record', 1]))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Export
    // ------------------------------------------------------------------

    public function test_the_export_streams_csv_and_honours_the_filters(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.audit-trail.export', ['source' => 'workflow']));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        // `fputcsv` quotes the header cells containing a space.
        $this->assertStringContainsString('Source,"Occurred at",Event', $csv);
        $this->assertStringContainsString('pre_dpc: draft', $csv);
        $this->assertStringNotContainsString('The proposal was forwarded', $csv);
    }

    /**
     * An audit export is exactly the file somebody opens in Excel without
     * thinking, so a cell that would be read as a formula is neutralised — the
     * same treatment the import error report already gets.
     */
    public function test_the_export_neutralises_formula_injection(): void
    {
        ActivityTimeline::query()->create([
            'schema_version' => ActivityTimeline::SCHEMA_VERSION,
            'event' => 'note.added',
            'description' => '=HYPERLINK("http://evil.example","click")',
            'actor_name' => 'Someone',
            'occurred_at' => now(),
        ]);

        $csv = $this->actingAs($this->admin)
            ->get(route('admin.audit-trail.export', ['source' => 'timeline']))
            ->streamedContent();

        $this->assertStringContainsString("\t=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }

    // ------------------------------------------------------------------
    // Fixture
    // ------------------------------------------------------------------

    private function seedTrail(): void
    {
        WorkflowTransition::query()->create([
            'workflow' => 'pre_dpc',
            'subject_type' => 'App\\Domain\\Dpc\\Models\\DpcProposal',
            'subject_id' => 7,
            'event' => 'submit',
            'from_state' => 'draft',
            'to_state' => 'submitted',
            'actor_id' => $this->officer->id,
            'remarks' => 'Returned for a missing CR year',
            'ip_address' => '203.0.113.9',
            'occurred_at' => Carbon::parse('2026-08-01 10:00:00'),
        ]);

        ActivityTimeline::query()->create([
            'schema_version' => ActivityTimeline::SCHEMA_VERSION,
            'subject_type' => 'App\\Domain\\Dpc\\Models\\DpcProposal',
            'subject_id' => 7,
            'event' => 'dpc_proposal.forwarded',
            'description' => 'The proposal was forwarded to the Commission',
            'causer_type' => User::class,
            'causer_id' => $this->officer->id,
            'actor_uuid' => $this->officer->uuid,
            'actor_name' => $this->officer->name,
            'properties' => ['dpc_code' => 'DPC-2026-SE-0001'],
            'occurred_at' => Carbon::parse('2026-08-05 11:30:00'),
        ]);

        // A second timeline row on a different subject, so subject filtering has
        // something to exclude.
        ActivityTimeline::query()->create([
            'schema_version' => ActivityTimeline::SCHEMA_VERSION,
            'subject_type' => 'App\\Domain\\Circulation\\Models\\DpcCirculation',
            'subject_id' => 3,
            'event' => 'circulation_note.signed',
            'description' => 'The circulation note was signed',
            'actor_name' => 'Smt. Kavita Deshmukh',
            'occurred_at' => Carbon::parse('2026-08-16 09:15:00'),
        ]);

        Audit::query()->create([
            'user_type' => User::class,
            'user_id' => $this->admin->id,
            'event' => 'updated',
            'auditable_type' => 'App\\Domain\\Dpc\\Models\\DpcProposal',
            'auditable_id' => 7,
            'old_values' => ['status' => 'submitted'],
            'new_values' => ['status' => 'approved'],
            'url' => 'http://localhost/dpc/7/approve',
            'ip_address' => '203.0.113.9',
            'created_at' => Carbon::parse('2026-08-06 12:00:00'),
        ]);
    }
}
