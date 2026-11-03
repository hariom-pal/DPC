<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\PayScale;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * UAT, 2026-11-02: a deleted master entry may be entered again — from its screen or
 * by bulk upload — and comes back as the same row instead of failing on the unique
 * index. The Pay Scale upload carries the entry pay the screen requires.
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
        $this->assertSame([$scale->id, 79000], [$again->id, $again->minimum_pay]);
    }

    public function test_the_upload_carries_the_entry_pay_and_brings_a_deleted_entry_back(): void
    {
        $template = (string) $this->actingAs($this->admin)->get(route('admin.master-data.template', 'pay-scales'))->streamedContent();
        $this->assertStringContainsString('minimum_pay', $template);

        $deleted = PayScale::query()->create(['code' => 'L12', 'label' => 'Old label', 'minimum_pay' => 1, 'status' => 'active']);
        $deleted->delete();

        $this->upload($template)->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->assertSame(2, PayScale::query()->count());
        $restored = PayScale::query()->where('code', 'L12')->sole();
        $this->assertSame([$deleted->id, 78800], [$restored->id, $restored->minimum_pay]);
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
