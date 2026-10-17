<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\RecordReason;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\LookupMasterSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Reason Master — change register of 2026-08-27, item 16.
 *
 * > *Create a configurable Reason Master. Suggested values — Record Not Available,
 * > Officer on Deputation, Department Delay, Court Case, Record Lost, Newly
 * > Appointed, Other. If "Other" is selected, Remarks become mandatory.*
 *
 * The register calls the seven values *suggested*, so the point of these tests is
 * that the list is **configurable** rather than that it contains those seven: the
 * seeded set is checked once, and everything else checks that an Administrator can
 * change it.
 */
class RecordReasonMasterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::Admin->value);

        return $admin;
    }

    /**
     * The seven suggested values are seeded, and exactly one of them demands remarks.
     *
     * `requires_remarks` on "Other" alone is the register's own rule. Pinned because
     * seeding it on all seven would make every gap need a paragraph, and seeding it on
     * none would let "Other" mean nothing.
     */
    public function test_the_seven_suggested_reasons_are_seeded(): void
    {
        $this->seed(LookupMasterSeeder::class);

        // Seven from the register, and the three grounds rule 3.2 of the Coder
        // Instructions names for an unavailable CR (2026-10-17).
        $this->assertSame(10, RecordReason::query()->count());
        foreach (['nrc_issued', 'long_leave', 'suspended'] as $code) {
            $this->assertTrue(RecordReason::query()->where('code', $code)->exists(), $code);
        }
        $this->assertSame(
            ['other'],
            RecordReason::query()->where('requires_remarks', true)->pluck('code')->all(),
        );
    }

    public function test_an_administrator_can_add_a_reason(): void
    {
        $this->seed(LookupMasterSeeder::class);

        $this->actingAs($this->admin())
            ->post(route('admin.record-reasons.store'), [
                'code' => 'vigilance_clearance',
                'name_en' => 'Awaiting Vigilance Clearance',
                'name_hi' => 'सतर्कता अनापत्ति की प्रतीक्षा',
                'requires_remarks' => '1',
                'sort_order' => 8,
                'status' => MasterStatus::Active->value,
            ])
            ->assertRedirect(route('admin.record-reasons.index'));

        $reason = RecordReason::query()->where('code', 'vigilance_clearance')->firstOrFail();

        $this->assertTrue($reason->requires_remarks);
        $this->assertSame(8, $reason->sort_order);
    }

    /**
     * An unticked checkbox posts nothing, and nothing has to mean **false**.
     *
     * Without `prepareForValidation()` the flag could be turned on and never off —
     * the more dangerous direction, because the Commission would be unable to
     * withdraw a mandatory-remarks rule it had set by mistake.
     */
    public function test_clearing_the_remarks_flag_actually_clears_it(): void
    {
        $this->seed(LookupMasterSeeder::class);

        $other = RecordReason::query()->where('code', 'other')->firstOrFail();
        $this->assertTrue($other->requires_remarks);

        $this->actingAs($this->admin())
            ->put(route('admin.record-reasons.update', $other), [
                'code' => $other->code,
                'name_en' => $other->name_en,
                'name_hi' => $other->name_hi,
                'sort_order' => $other->sort_order,
                'status' => MasterStatus::Active->value,
                // 'requires_remarks' deliberately absent — an unticked box.
            ])
            ->assertRedirect(route('admin.record-reasons.index'));

        $this->assertFalse($other->fresh()->requires_remarks);
    }

    public function test_a_duplicate_code_is_refused(): void
    {
        $this->seed(LookupMasterSeeder::class);

        $this->actingAs($this->admin())
            ->post(route('admin.record-reasons.store'), [
                'code' => 'other',
                'name_en' => 'Something else',
                'name_hi' => 'अन्य कुछ',
                'sort_order' => 9,
                'status' => MasterStatus::Active->value,
            ])
            ->assertSessionHasErrors('code');
    }

    /**
     * The list is ordered by `sort_order`, which is the point of having the column:
     * an Administrator orders the reasons by how often they are used, and no
     * alphabetical sort can guess that.
     */
    public function test_the_index_is_ordered_by_the_administrators_own_order(): void
    {
        $this->seed(LookupMasterSeeder::class);

        $first = RecordReason::query()->orderBy('sort_order')->firstOrFail();
        $last = RecordReason::query()->orderByDesc('sort_order')->firstOrFail();

        $this->assertNotSame($first->id, $last->id);

        $response = $this->actingAs($this->admin())->get(route('admin.record-reasons.index'));

        $response->assertOk();
        $response->assertSeeInOrder([$first->name_en, $last->name_en]);
    }

    /**
     * `scopeSelectable()` is narrower than "not deleted".
     *
     * A withdrawn reason stays attached to the gaps that already cite it —
     * withdrawing it does not rewrite why a record was missing two years ago — but it
     * must not appear on a new one.
     */
    public function test_a_withdrawn_reason_leaves_the_selectable_list(): void
    {
        $this->seed(LookupMasterSeeder::class);

        $reason = RecordReason::query()->where('code', 'court_case')->firstOrFail();

        $this->assertTrue(RecordReason::query()->selectable()->get()->contains($reason));

        $reason->forceFill(['status' => MasterStatus::Inactive->value])->save();

        $this->assertFalse(RecordReason::query()->selectable()->get()->contains('id', $reason->id));
    }

    public function test_an_authorized_officer_cannot_reach_the_master(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(LookupMasterSeeder::class);

        $ao = User::factory()->create();
        $ao->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($ao)->get(route('admin.record-reasons.index'))->assertForbidden();
    }
}
