<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\ReservationPolicy;
use App\Domain\Master\Services\PhReservationPolicy;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Only PH is reserved in promotion today (rule 2.1). It is a Reservation Policy: the
 * percentage of the promotion vacancies, and floor or ceil for a decimal part.
 */
class PhReservationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dpc.reservation.category_wise' => false]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);
    }

    private function ph(): ReservationCategory
    {
        return ReservationCategory::query()->where('code', 'PH')->firstOrFail();
    }

    private function onDate(string $date): DpcProposal
    {
        return (new DpcProposal)->forceFill(['proposal_date' => $date]);
    }

    public function test_ph_is_the_only_active_category_and_has_the_policy_in_force(): void
    {
        $this->assertSame(['PH'], ReservationCategory::query()->where('status', 'active')->pluck('code')->all());
        $this->assertSame(5, ReservationCategory::query()->where('status', 'inactive')->count(), 'The others are kept, inactive.');

        $terms = app(PhReservationPolicy::class)->termsFor($this->onDate('2026-06-01'));
        $this->assertNotNull($terms['source'], 'The migration records the PH policy the portal applied.');
        $this->assertSame('3.00', $terms['percentage']);
        $this->assertSame('floor', $terms['rounding']);
    }

    public function test_the_administrator_sets_the_percentage_rounding_and_pdf(): void
    {
        ReservationPolicy::query()->update(['effective_to' => '2026-12-31']);

        $this->actingAs($this->admin)->post(route('admin.reservation-policies.store'), [
            'reservation_category_id' => $this->ph()->id,
            'percentage' => '4',
            'rounding' => 'ceil',
            'effective_from' => '2027-01-01',
            'status' => 'active',
            'document' => UploadedFile::fake()->create('ph-order.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.reservation-policies.index'));

        $policy = ReservationPolicy::query()->latest('id')->firstOrFail();
        $this->assertSame('ceil', $policy->rounding);
        $this->assertSame('ph-order.pdf', $policy->original_name);

        $book = app(PhReservationPolicy::class);
        $this->assertSame('4.00', $book->termsFor($this->onDate('2027-03-01'))['percentage']);
        $this->assertSame('ceil', $book->termsFor($this->onDate('2027-03-01'))['rounding']);
        $this->assertSame('3.00', $book->termsFor($this->onDate('2026-03-01'))['percentage'], 'An earlier DPC keeps the policy of its date.');

        $this->actingAs($this->admin)->get(route('admin.reservation-policies.index'))
            ->assertOk()
            ->assertSee(__('admin.reservation_policies.roundings.ceil'));
        $this->actingAs($this->admin)->get(route('admin.reservation-policies.document', $policy))
            ->assertOk()->assertDownload('ph-order.pdf');
    }

    public function test_only_floor_or_ceil_is_accepted(): void
    {
        $this->actingAs($this->admin)->post(route('admin.reservation-policies.store'), [
            'reservation_category_id' => $this->ph()->id,
            'percentage' => '3',
            'rounding' => 'round',
            'effective_from' => '2027-01-01',
            'status' => 'active',
        ])->assertSessionHasErrors('rounding');
    }

    public function test_floor_drops_even_point_nine_and_ceil_rounds_up(): void
    {
        $ph = app(PhReservationPolicy::class);

        $this->assertSame(0, $ph->posts(33, '3.00', 'floor'));   // 0.99
        $this->assertSame(2, $ph->posts(67, '3.00', 'floor'));   // 2.01
        $this->assertSame(3, $ph->posts(100, '3.00', 'floor'));  // exactly 3, never 2.999…
        $this->assertSame(1, $ph->posts(33, '3.00', 'ceil'));
        $this->assertSame(3, $ph->posts(67, '3.00', 'ceil'));
        $this->assertSame(3, $ph->posts(100, '3.00', 'ceil'));
    }

    public function test_the_policy_form_offers_floor_and_ceil(): void
    {
        $this->actingAs($this->admin)->get(route('admin.reservation-policies.create'))
            ->assertOk()
            ->assertSee('name="rounding"', false)
            ->assertSee(__('admin.reservation_policies.roundings.floor'))
            ->assertSee('name="document"', false);
    }
}
