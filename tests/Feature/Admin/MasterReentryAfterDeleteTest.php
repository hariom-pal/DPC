<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\PayScale;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A deleted master entry may be entered again, from its screen or by bulk upload
 * (UAT, 2026-11-02). Since 2026-11-03 it is a **new** record: "soft delete matlab
 * delete hi hai except trail log". The deleted row stays deleted, for the trail.
 * The Pay Scale upload carries the entry pay the screen requires.
 */
class MasterReentryAfterDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);
    }

    private function upload(string $csv): TestResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'ps').'.csv';
        file_put_contents($path, $csv);

        return $this->actingAs($this->admin)->from(route('admin.pay-scales.index'))
            ->post(route('admin.master-data.import', 'pay-scales'), ['file' => new UploadedFile($path, 'pay.csv', 'text/csv', null, true)]);
    }

    public function test_a_deleted_entry_is_entered_again_from_the_screen(): void
    {
        $payload = ['code' => 'L12', 'label' => 'Level-12 (78800-209200)', 'minimum_pay' => '78800', 'status' => 'active'];
        $this->actingAs($this->admin)->post(route('admin.pay-scales.store'), $payload)->assertSessionHasNoErrors();
        $scale = PayScale::query()->sole();

        $this->actingAs($this->admin)->delete(route('admin.pay-scales.destroy', $scale))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($scale);

        $this->actingAs($this->admin)->post(route('admin.pay-scales.store'), [...$payload, 'minimum_pay' => '79000'])->assertSessionHasNoErrors();

        $again = PayScale::query()->sole();
        $this->assertNotSame($scale->id, $again->id);
        $this->assertSame(79000, $again->minimum_pay);

        // The deleted one is untouched, and its creation and deletion are on the trail.
        $this->assertSoftDeleted($scale);
        $this->assertSame(78800, PayScale::withTrashed()->findOrFail($scale->id)->minimum_pay);
        $this->assertSame(['created', 'deleted'], DB::table('audits')
            ->where('auditable_type', $scale->getMorphClass())->where('auditable_id', $scale->id)
            ->orderBy('id')->pluck('event')->all());
    }

    public function test_the_upload_carries_the_entry_pay_and_enters_a_deleted_entry_again(): void
    {
        $template = (string) $this->actingAs($this->admin)->get(route('admin.master-data.template', 'pay-scales'))->streamedContent();
        $this->assertStringContainsString('minimum_pay', $template);

        $deleted = PayScale::query()->create(['code' => 'L12', 'label' => 'Old label', 'minimum_pay' => 1, 'status' => 'active']);
        $deleted->delete();

        $this->upload($template)->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->assertSame(2, PayScale::query()->count());
        $again = PayScale::query()->where('code', 'L12')->sole();
        $this->assertNotSame($deleted->id, $again->id);
        $this->assertSame(78800, $again->minimum_pay);
        $this->assertSame('Old label', PayScale::onlyTrashed()->findOrFail($deleted->id)->label);
    }

    /**
     * Twice: a value deleted, entered again, deleted again and entered a third time.
     * The database itself allows it, not only the form.
     */
    public function test_a_value_may_be_deleted_and_entered_again_more_than_once(): void
    {
        foreach ([1, 2, 3] as $round) {
            PayScale::query()->create(['code' => 'L13', 'label' => "Level-13 round {$round}", 'minimum_pay' => $round, 'status' => 'active']);

            if ($round < 3) {
                PayScale::query()->where('code', 'L13')->sole()->delete();
            }
        }

        $this->assertSame(3, PayScale::withTrashed()->where('code', 'L13')->count());
        $this->assertSame('Level-13 round 3', PayScale::query()->where('code', 'L13')->sole()->label);
    }

    /**
     * Only the deleted row gives up its value: two live rows still cannot share it.
     */
    public function test_two_live_rows_still_cannot_share_a_value(): void
    {
        PayScale::query()->create(['code' => 'L14', 'label' => 'Level-14', 'minimum_pay' => 1, 'status' => 'active']);

        $this->expectException(UniqueConstraintViolationException::class);
        PayScale::query()->create(['code' => 'L14', 'label' => 'Level-14 again', 'minimum_pay' => 1, 'status' => 'active']);
    }

    public function test_a_non_numeric_entry_pay_is_refused_with_its_line(): void
    {
        $this->upload("code,label,level,grade_pay,minimum_pay,status\nL14,Level-14,14,,abc,active\nL15,Level-15,15,,,active\n")
            ->assertSessionHasErrors('file');

        $errors = session('errors')->get('file');
        $this->assertContains(__('admin.master_data.errors.integer', ['line' => 2, 'column' => 'minimum_pay']), $errors);
        $this->assertContains(__('admin.master_data.errors.missing', ['line' => 3, 'column' => 'minimum_pay']), $errors);
        $this->assertSame(0, PayScale::query()->count());
    }
}
