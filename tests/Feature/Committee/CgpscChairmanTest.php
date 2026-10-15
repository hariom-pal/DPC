<?php

declare(strict_types=1);

namespace Tests\Feature\Committee;

use App\Domain\Authentication\Models\User;
use App\Domain\Committee\Models\CgpscChairman;
use App\Domain\Committee\Services\CgpscChairmanService;
use App\Domain\Master\Models\Designation;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Who the CGPSC Chairman is, and was (change register of 2026-08-26, item 9 as
 * clarified).
 *
 * **The property every test here defends:** a committee composed while one Chairman
 * held office must still name that Chairman after a successor is appointed. That is
 * only true if appointing a successor opens a *new tenure* and closes the old one
 * rather than editing the record in place — so most of what follows asserts what did
 * **not** change.
 */
class CgpscChairmanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);

        // `Tests\TestCase` seeds a serving Chairman so the DPC workflow can run.
        // These tests are about that record itself, so they start from nothing.
        CgpscChairman::query()->delete();
    }

    private function service(): CgpscChairmanService
    {
        return app(CgpscChairmanService::class);
    }

    // ------------------------------------------------------------ appointment

    public function test_the_administrator_appoints_a_chairman(): void
    {
        $this->actingAs($this->admin)->post(route('admin.cgpsc-chairman.store'), [
            'name' => 'Dr. A. K. Shrivastava',
            'designation' => 'Chairman, CGPSC',
            'email' => 'chairman@cgpsc.gov.in',
            'mobile' => '9425000001',
        ])->assertRedirect(route('admin.cgpsc-chairman.index'))->assertSessionHasNoErrors();

        $chairman = CgpscChairman::query()->serving()->sole();

        $this->assertSame('Dr. A. K. Shrivastava', $chairman->name);
        $this->assertSame(Carbon::today()->toDateString(), $chairman->effective_from->toDateString());
        $this->assertNull($chairman->effective_to);
    }

    /**
     * The Chairman's rank comes from the Designation master, not from a text box
     * (DPC-MODULE-FIX-001, second pass, 2026-09-06).
     *
     * The office sits on every committee, so this screen obeys the same rule the
     * committee screen does. What is *stored* is still the name — the row is a
     * snapshot, so a committee composed last month keeps naming the Chairman as
     * they were recorded then — and the conflicting free text in the payload is
     * there to prove the id wins.
     */
    public function test_the_chairmans_designation_comes_from_the_master(): void
    {
        $designation = Designation::query()->create([
            'name' => 'Chairman, Chhattisgarh Public Service Commission',
            'status' => MasterStatus::Active->value,
        ]);

        $this->actingAs($this->admin)->post(route('admin.cgpsc-chairman.store'), [
            'name' => 'Dr. A. K. Shrivastava',
            'designation_id' => $designation->id,
            'designation' => 'Something Somebody Typed',
            'mobile' => '9425000001',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            'Chairman, Chhattisgarh Public Service Commission',
            CgpscChairman::query()->serving()->sole()->designation,
        );
    }

    public function test_a_chairman_designation_outside_the_master_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('admin.cgpsc-chairman.store'), [
            'name' => 'Dr. A. K. Shrivastava',
            'designation_id' => 987654,
            'mobile' => '9425000001',
        ])->assertSessionHasErrors('designation_id');

        $this->assertSame(0, CgpscChairman::query()->count());
    }

    public function test_the_appointment_form_offers_the_designation_master(): void
    {
        Designation::query()->create(['name' => 'Chairman, CGPSC', 'status' => MasterStatus::Active->value]);
        Designation::query()->create(['name' => 'Retired Rank', 'status' => MasterStatus::Inactive->value]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.cgpsc-chairman.create'))
            ->assertOk();

        $response->assertSee('name="designation_id"', false);
        $response->assertSee('Chairman, CGPSC');
        $response->assertDontSee('Retired Rank');
        $response->assertDontSee('name="designation"', false);
    }

    public function test_appointing_a_successor_closes_the_incumbents_tenure(): void
    {
        Carbon::setTestNow('2026-03-01');
        $first = $this->service()->appoint(['name' => 'First Chairman'], $this->admin);

        Carbon::setTestNow('2026-09-01');
        $second = $this->service()->appoint(['name' => 'Second Chairman'], $this->admin);

        $first->refresh();

        $this->assertFalse($first->isServing());
        $this->assertTrue($second->isServing());

        // Closed the day before the successor begins, so no day is covered twice.
        $this->assertSame('31-08-2026', $first->effective_to?->format('d-m-Y'));

        Carbon::setTestNow();
    }

    public function test_only_one_chairman_serves_at_a_time(): void
    {
        $this->service()->appoint(['name' => 'First Chairman'], $this->admin);
        $this->service()->appoint(['name' => 'Second Chairman'], $this->admin);

        $this->assertSame(2, CgpscChairman::query()->count());
        $this->assertSame(1, CgpscChairman::query()->serving()->count());
    }

    public function test_the_database_refuses_two_serving_chairmen(): void
    {
        $this->service()->appoint(['name' => 'First Chairman'], $this->admin);

        $this->expectException(UniqueConstraintViolationException::class);

        $duplicate = new CgpscChairman(['name' => 'Second', 'effective_from' => Carbon::today()]);
        $duplicate->forceFill(['active_flag' => 1])->save();
    }

    public function test_a_tenure_cannot_begin_before_the_one_it_succeeds(): void
    {
        Carbon::setTestNow('2026-06-01');
        $this->service()->appoint(['name' => 'First Chairman'], $this->admin);

        $this->expectException(RuntimeException::class);

        $this->service()->appoint(['name' => 'Second'], $this->admin, Carbon::parse('2026-01-01'));

        Carbon::setTestNow();
    }

    public function test_a_future_appointment_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('admin.cgpsc-chairman.store'), [
            'name' => 'Not Yet In Office',
            'effective_from' => Carbon::tomorrow()->toDateString(),
        ])->assertSessionHasErrors('effective_from');

        $this->assertSame(0, CgpscChairman::query()->count());
    }

    // ---------------------------------------------------------------- amending

    /**
     * Amending corrects particulars. It must not be able to change *who* holds the
     * office, because that would rewrite every committee composed during the tenure.
     */
    public function test_amending_cannot_change_the_person_or_the_start_date(): void
    {
        $incumbent = $this->service()->appoint([
            'name' => 'Dr. A. K. Shrivastava',
            'user_id' => null,
        ], $this->admin, Carbon::parse('2026-01-01'));

        $someoneElse = User::factory()->create();

        $this->actingAs($this->admin)->put(route('admin.cgpsc-chairman.update', $incumbent), [
            'name' => 'Dr. A. K. Shrivastava (corrected)',
            'mobile' => '9425000009',
            'user_id' => $someoneElse->id,
            'effective_from' => '2020-01-01',
        ])->assertSessionHasNoErrors();

        $incumbent->refresh();

        $this->assertSame('Dr. A. K. Shrivastava (corrected)', $incumbent->name);
        $this->assertSame('9425000009', $incumbent->mobile);

        // Neither of the two things an amendment may not touch has moved.
        $this->assertNull($incumbent->user_id);
        $this->assertSame('01-01-2026', $incumbent->effective_from->format('d-m-Y'));
    }

    // ------------------------------------------------------------------ tenure

    /**
     * The whole reason this is a dated chain rather than a flag on a user.
     */
    public function test_the_chairman_serving_on_a_past_date_is_still_answerable(): void
    {
        Carbon::setTestNow('2026-01-01');
        $this->service()->appoint(['name' => 'First Chairman'], $this->admin);

        Carbon::setTestNow('2026-09-01');
        $this->service()->appoint(['name' => 'Second Chairman'], $this->admin);

        $this->assertSame('First Chairman', $this->service()->servingOn(Carbon::parse('2026-04-01'))?->name);
        $this->assertSame('Second Chairman', $this->service()->servingOn(Carbon::parse('2026-10-01'))?->name);

        // Before anybody held the office, the honest answer is nobody.
        $this->assertNull($this->service()->servingOn(Carbon::parse('2025-01-01')));

        Carbon::setTestNow();
    }

    /**
     * Appointing a Chairman states a fact about the Commission. It must not hand out
     * a permission as a side effect.
     */
    public function test_appointing_grants_no_role(): void
    {
        $user = User::factory()->create();

        $this->service()->appoint(['name' => 'Dr. A. K. Shrivastava', 'user_id' => $user->id], $this->admin);

        $this->assertFalse($user->fresh()->holdsRole(Role::Chairman));
    }

    public function test_the_appointment_is_recorded_on_the_timeline(): void
    {
        $this->service()->appoint(['name' => 'Dr. A. K. Shrivastava'], $this->admin);

        $this->assertDatabaseHas('activity_timelines', ['event' => 'cgpsc_chairman.appointed']);
    }

    // ------------------------------------------------------------------ access

    public function test_there_is_no_way_to_delete_a_chairman(): void
    {
        // A Chairman who has left office is succeeded, not deleted — every DPC
        // composed during their tenure names them.
        $this->assertFalse(app('router')->has('admin.cgpsc-chairman.destroy'));
    }

    public function test_only_the_administrator_reaches_the_screen(): void
    {
        $officer = User::factory()->create();
        $officer->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($officer)->get(route('admin.cgpsc-chairman.index'))->assertForbidden();
        $this->actingAs($officer)->post(route('admin.cgpsc-chairman.store'), ['name' => 'X'])->assertForbidden();
    }
}
