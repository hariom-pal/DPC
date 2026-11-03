<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\DepartmentPostChange;
use App\Domain\Master\Models\PayScale;
use App\Domain\Master\Services\DepartmentPostMappingService;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * The Department Post Mapping as an effective-dated record authorised by a
 * Government Order.
 *
 * **The property every test here defends:** a DPC conducted under one mapping must
 * still be auditable against the order that authorised it after the Government
 * issues a new one. That is only true if a new order produces a *new row* and the
 * old row survives untouched — so most of what follows asserts what did **not**
 * change.
 */
class DepartmentPostMappingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Department $department;

    private PayScale $level13;

    private PayScale $level14;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);

        $this->department = Department::query()->create([
            'code' => 'HE', 'name' => 'Higher Education', 'status' => 'active',
        ]);

        $this->level13 = PayScale::query()->create(['label' => 'Level-13', 'status' => MasterStatus::Active->value]);
        $this->level14 = PayScale::query()->create(['label' => 'Level-14', 'status' => MasterStatus::Active->value]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'department_id' => $this->department->id,
            'name' => 'Joint Director',
            'class_level' => 'I',
            'pay_scale_id' => $this->level14->id,
            'promotion_duration_years' => 5,

            // Item 10 (2026-08-26): the orders are a repeater, so even one arrives
            // as a row rather than as two fields on the mapping.
            'orders' => [['government_order_number' => 'F-12/2026', 'government_order_date' => '2026-01-01']],

            'effective_from' => '2026-01-01',
            'status' => MasterStatus::Active->value,
        ], $overrides);
    }

    /**
     * The number on a mapping's earliest order — what `government_order_number`
     * used to hold before item 10 made the orders plural.
     */
    private function orderNumber(DepartmentPost $post): ?string
    {
        return $post->orders()->first()?->government_order_number;
    }

    private function mapping(): DepartmentPost
    {
        $this->actingAs($this->admin)
            ->post(route('admin.department-posts.store'), $this->payload())
            ->assertSessionHasNoErrors();

        return DepartmentPost::query()->latest('id')->firstOrFail();
    }

    private function pdf(string $name = 'go.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 40, 'application/pdf');
    }

    // ------------------------------------------------------------------
    // The Government Order on the mapping
    // ------------------------------------------------------------------

    public function test_a_mapping_records_its_government_order_and_effective_date(): void
    {
        $post = $this->mapping();

        $order = $post->orders()->sole();

        $this->assertSame('F-12/2026', $order->government_order_number);
        $this->assertSame('01-01-2026', $order->government_order_date->format('d-m-Y'));
        $this->assertSame('01-01-2026', $post->effective_from?->format('d-m-Y'));
        $this->assertNull($post->effective_to, 'A mapping in force has no end date until one supersedes it.');
        $this->assertTrue($post->isInForce());
    }

    public function test_the_order_is_optional_because_older_mappings_have_none(): void
    {
        $this->actingAs($this->admin)->post(route('admin.department-posts.store'), $this->payload([
            'orders' => [[]],
        ]))->assertSessionHasNoErrors();

        $post = DepartmentPost::query()->latest('id')->firstOrFail();

        // Said out loud rather than left blank: a blank space beside the words
        // "Government Order" reads as a bug rather than as a fact.
        $this->assertSame('Not recorded', $post->governmentOrderLabel());
    }

    public function test_a_pdf_is_stored_hashed_and_downloadable(): void
    {
        $this->actingAs($this->admin)->post(
            route('admin.department-posts.store'),
            $this->payload([
                'orders' => [[
                    'government_order_number' => 'F-12/2026',
                    'government_order_date' => '2026-01-01',
                    'document' => $this->pdf('F-12-2026.pdf'),
                ]],
            ]),
        )->assertSessionHasNoErrors();

        $post = DepartmentPost::query()->latest('id')->firstOrFail();
        $order = $post->orders()->sole();

        $this->assertTrue($order->hasDocument());
        $this->assertSame('F-12-2026.pdf', $order->original_name);
        $this->assertSame(64, strlen((string) $order->sha256));
        $this->assertTrue($order->isIntact());
        Storage::disk((string) $order->disk)->assertExists((string) $order->path);

        $this->actingAs($this->admin)
            ->get(route('admin.department-posts.documents.download', [$post, $order]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /**
     * A file the server cannot write (2026-10-29, UAT: "Unable to create a
     * directory"): nothing is saved — the mapping included — and the form says so
     * without the server path.
     */
    public function test_an_order_that_cannot_be_stored_saves_nothing_and_says_so(): void
    {
        config([
            'filesystems.disks.unwritable' => ['driver' => 'local', 'root' => '/proc/dpc-unwritable', 'throw' => false],
            'dpc.documents.disk' => 'unwritable',
        ]);

        $this->actingAs($this->admin)->from(route('admin.department-posts.create'))->post(
            route('admin.department-posts.store'),
            $this->payload(['orders' => [['government_order_number' => 'F-12/2026', 'government_order_date' => '2026-01-01', 'document' => $this->pdf('F-12-2026.pdf')]]]),
        )->assertRedirect(route('admin.department-posts.create'))
            ->assertSessionHasErrors(['orders' => __('admin.department_posts.storage_failed')]);

        $this->assertSame(0, DepartmentPost::query()->count());
    }

    /**
     * The Department Admin's own department is preselected and not offered (2026-10-29).
     */
    public function test_the_department_admins_department_is_preselected(): void
    {
        $da = User::factory()->assignedTo($this->department, Role::DepartmentAdmin)->create();

        $this->actingAs($da)->get(route('department.posts.create'))->assertOk()
            ->assertSee('name="department_id" value="'.$this->department->id.'"', false)
            ->assertSee('value="Higher Education" readonly', false)
            ->assertDontSee('<select class="form-select" id="department_id"', false);
    }

    /**
     * Item 10, the thing the old shape could not do: several orders on one mapping,
     * each with its own number, date and PDF, all kept.
     */
    public function test_a_mapping_may_hold_several_government_orders(): void
    {
        $this->actingAs($this->admin)->post(
            route('admin.department-posts.store'),
            $this->payload([
                'orders' => [
                    ['government_order_number' => 'F-12/2026', 'government_order_date' => '2026-01-01',
                        'document' => $this->pdf('original.pdf')],
                    ['government_order_number' => 'F-31/2026', 'government_order_date' => '2026-04-15',
                        'document' => $this->pdf('corrigendum.pdf')],
                    // Cited but not yet scanned, which the register permits.
                    ['government_order_number' => 'F-55/2026', 'government_order_date' => '2026-06-01'],
                ],
            ]),
        )->assertSessionHasNoErrors();

        $post = DepartmentPost::query()->latest('id')->firstOrFail();
        $orders = $post->orders;

        $this->assertCount(3, $orders);

        // Oldest first, by the date on the instrument.
        $this->assertSame(
            ['F-12/2026', 'F-31/2026', 'F-55/2026'],
            $orders->pluck('government_order_number')->all(),
        );

        $this->assertSame('original.pdf', $orders[0]->original_name);
        $this->assertSame('corrigendum.pdf', $orders[1]->original_name);
        $this->assertFalse($orders[2]->hasDocument());

        // Every one of them is named on the mapping's authority label.
        $this->assertStringContainsString('F-12/2026', $post->governmentOrderLabel());
        $this->assertStringContainsString('F-55/2026', $post->governmentOrderLabel());
    }

    public function test_the_same_order_cannot_be_attached_to_one_mapping_twice(): void
    {
        $post = $this->mapping();

        $this->expectException(UniqueConstraintViolationException::class);

        app(DepartmentPostMappingService::class)->recordOrder(
            $post, 'F-12/2026', Carbon::parse('2026-01-01'), null, $this->admin,
        );
    }

    public function test_an_order_row_needs_both_its_number_and_its_date(): void
    {
        $this->actingAs($this->admin)->post(route('admin.department-posts.store'), $this->payload([
            'orders' => [['government_order_number' => 'F-12/2026']],
        ]))->assertSessionHasErrors('orders.0.government_order_date');

        $this->actingAs($this->admin)->post(route('admin.department-posts.store'), $this->payload([
            'orders' => [['government_order_date' => '2026-01-01']],
        ]))->assertSessionHasErrors('orders.0.government_order_number');

        $this->assertSame(0, DepartmentPost::query()->count());
    }

    public function test_only_a_pdf_is_accepted(): void
    {
        foreach ([
            UploadedFile::fake()->create('order.docx', 20, 'application/msword'),
            UploadedFile::fake()->create('order.png', 20, 'image/png'),
            // The one an upload field on a government portal is actually probed
            // with: the right extension over the wrong content type.
            UploadedFile::fake()->create('order.pdf', 20, 'application/x-msdownload'),
        ] as $file) {
            $this->actingAs($this->admin)->post(
                route('admin.department-posts.store'),
                $this->payload([
                    'name' => 'Post '.$file->getClientOriginalName(),
                    'orders' => [[
                        'government_order_number' => 'F-12/2026',
                        'government_order_date' => '2026-01-01',
                        'document' => $file,
                    ]],
                ]),
            )->assertSessionHasErrors('orders.0.document');
        }

        $this->assertSame(0, DepartmentPost::query()->count());
    }

    /**
     * The register's rule: previous orders remain available for audit.
     */
    public function test_a_later_order_never_displaces_an_earlier_one(): void
    {
        $post = $this->mapping();
        $service = app(DepartmentPostMappingService::class);

        $first = $post->orders()->sole();

        $service->recordOrder($post, 'F-31/2026', Carbon::parse('2026-04-15'), $this->pdf('second.pdf'), $this->admin);

        $post->refresh();

        $this->assertSame(2, $post->orders()->count());

        // The earlier order is untouched, and it still answers for the mapping.
        $first->refresh();
        $this->assertSame('F-12/2026', $first->government_order_number);
        $this->assertSame('F-12/2026', $this->orderNumber($post), 'The earliest order still answers for the mapping.');
    }

    public function test_an_order_cannot_be_edited_or_deleted(): void
    {
        $post = $this->mapping();
        $order = app(DepartmentPostMappingService::class)
            ->recordOrder($post, 'F-31/2026', Carbon::parse('2026-04-15'), $this->pdf(), $this->admin);

        try {
            $order->forceFill(['government_order_number' => 'F-99/2026'])->save();
            $this->fail('A Government Order must refuse to be rewritten.');
        } catch (LogicException) {
            // Expected.
        }

        $this->expectException(LogicException::class);
        $order->delete();
    }

    // ------------------------------------------------------------------
    // Effective dating and supersession
    // ------------------------------------------------------------------

    // ------------------------------------------- sanctioned strength (2026-10-17)

    /**
     * The sanctioned strength left the mapping on 2026-10-17: it can change year by
     * year on its own order, so the Vacancy Master records it for each Vacancy Year.
     * The mapping's forms do not ask for it, a posted figure is not stored, and the
     * detail page says where it is kept.
     */
    public function test_the_mapping_no_longer_carries_a_sanctioned_strength(): void
    {
        $this->actingAs($this->admin)->get(route('admin.department-posts.create'))
            ->assertOk()
            ->assertDontSee('name="sanctioned_strength"', false);

        $this->actingAs($this->admin)
            ->post(route('admin.department-posts.store'), $this->payload(['sanctioned_strength' => 7]))
            ->assertSessionHasNoErrors();
        $post = DepartmentPost::query()->latest('id')->firstOrFail();
        $this->assertNull($post->getRawOriginal('sanctioned_strength'));

        $this->actingAs($this->admin)->get(route('admin.department-posts.supersede.form', $post))
            ->assertOk()
            ->assertDontSee('name="sanctioned_strength"', false);

        $this->actingAs($this->admin)->get(route('admin.department-posts.show', $post))
            ->assertOk()
            ->assertSee(__('admin.department_posts.show.strength_in_vacancy_master'));

        $this->actingAs($this->admin)->get(route('admin.department-posts.index'))
            ->assertOk()
            ->assertDontSee(__('admin.department_posts.form.sanctioned_strength'));
    }

    public function test_the_list_fits_the_screen_and_leaves_the_detail_to_the_post_page(): void
    {
        $post = $this->mapping();

        $html = $this->actingAs($this->admin)->get(route('admin.department-posts.index'))
            ->assertOk()
            ->assertSee(route('admin.department-posts.show', $post), false)
            ->assertSee(__('admin.department_posts.index.details_hint'))
            ->getContent();

        $this->assertSame(4, preg_match_all('/<th[\s>]/', (string) strstr((string) strstr($html, '<thead>'), '</thead>', true)));
        $this->assertStringNotContainsString(__('admin.department_posts.form.go_number'), $html);
        $this->assertStringNotContainsString(__('ui.promotion_duration'), $html);

        // Still there, one click away.
        $this->actingAs($this->admin)->get(route('admin.department-posts.show', $post))
            ->assertOk()
            ->assertSee(__('ui.promotion_duration'))
            ->assertSee(__('admin.department_posts.form.effective_from'));
    }

    public function test_a_new_order_supersedes_the_mapping_without_changing_it(): void
    {
        $post = $this->mapping();
        $originalId = $post->id;

        $this->actingAs($this->admin)->post(route('admin.department-posts.supersede', $post), [
            'class_level' => 'I',
            'pay_scale_id' => $this->level13->id,
            'promotion_duration_years' => 6,
            'government_order_number' => 'F-45/2026',
            'government_order_date' => '2026-08-01',
            'effective_from' => '2026-09-01',
        ])->assertSessionHasNoErrors();

        // The replacement is a *new row*, in force from its own date.
        $replacement = DepartmentPost::query()->latest('id')->firstOrFail();
        $this->assertNotSame($originalId, $replacement->id);
        $this->assertSame('Joint Director', $replacement->name, 'A new order re-authorises the same post.');
        $this->assertSame($this->level13->id, $replacement->pay_scale_id);
        $this->assertSame(6, $replacement->promotion_duration_years);
        $this->assertSame('F-45/2026', $this->orderNumber($replacement));
        $this->assertTrue($replacement->isInForce());

        // The old row keeps every one of its own terms — that is the whole point.
        $old = DepartmentPost::query()->findOrFail($originalId);
        $this->assertSame($this->level14->id, $old->pay_scale_id, 'The superseded mapping must keep its own pay scale.');
        $this->assertSame(5, $old->promotion_duration_years);
        $this->assertSame('F-12/2026', $this->orderNumber($old));
        $this->assertFalse($old->isInForce());
        $this->assertSame(MasterStatus::Inactive, $old->status);

        // Closed the day before its successor begins, so no day is covered twice.
        $this->assertSame('31-08-2026', $old->effective_to?->format('d-m-Y'));
    }

    /**
     * A mapping saved as Draft is not in force, but it is not superseded either
     * (2026-10-30): it keeps its status and stays editable.
     */
    public function test_a_draft_mapping_is_not_shown_as_superseded(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.department-posts.store'), $this->payload(['status' => MasterStatus::Draft->value]))
            ->assertSessionHasNoErrors();
        $draft = DepartmentPost::query()->latest('id')->firstOrFail();

        $this->assertFalse($draft->isInForce());
        $this->assertFalse($draft->isSuperseded());

        $this->get(route('admin.department-posts.index'))->assertOk()
            ->assertDontSee(__('admin.department_posts.show.superseded'))
            ->assertSee(route('admin.department-posts.edit', $draft), false);
        $this->get(route('admin.department-posts.show', $draft))->assertOk()
            ->assertDontSee(__('admin.department_posts.show.superseded'));
        $this->get(route('admin.department-posts.edit', $draft))->assertOk();

        // Activated later from its own edit form.
        $this->put(route('admin.department-posts.update', $draft), $this->payload(['status' => MasterStatus::Active->value, 'orders' => []]))
            ->assertSessionHasNoErrors();
        $this->assertTrue($draft->fresh()->isInForce());

        // A superseded mapping is history: not editable.
        $this->post(route('admin.department-posts.supersede', $draft), [
            'class_level' => 'I', 'pay_scale_id' => $this->level13->id, 'promotion_duration_years' => 6,
            'government_order_number' => 'F-45/2026', 'government_order_date' => '2026-08-01', 'effective_from' => '2026-09-01',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($draft->fresh()->isSuperseded());
        $this->get(route('admin.department-posts.edit', $draft))->assertForbidden();
    }

    public function test_only_one_mapping_is_in_force_at_a_time(): void
    {
        $post = $this->mapping();

        app(DepartmentPostMappingService::class)->supersede($post, [
            'class_level' => 'I',
            'pay_scale_id' => $this->level13->id,
            'promotion_duration_years' => 6,
            'government_order_number' => 'F-45/2026',
            'government_order_date' => '2026-08-01',
            'effective_from' => '2026-09-01',
        ], $this->admin);

        $this->assertSame(2, DepartmentPost::query()->count());
        $this->assertSame(1, DepartmentPost::query()->inForce()->count());
        $this->assertSame(1, DepartmentPost::query()->superseded()->count());
    }

    public function test_the_database_refuses_two_mappings_in_force_for_one_post(): void
    {
        $this->mapping();

        $this->expectException(UniqueConstraintViolationException::class);

        $duplicate = new DepartmentPost([
            'department_id' => $this->department->id,
            'name' => 'Joint Director',
            'class_level' => 'I',
            'effective_from' => '2026-06-01',
        ]);
        $duplicate->status = MasterStatus::Active;
        $duplicate->active_flag = 1;
        $duplicate->save();
    }

    /**
     * Superseded rows keep the post's name, so uniqueness has to be about the
     * mapping *in force* rather than about the name.
     */
    public function test_a_post_can_be_registered_again_after_its_mapping_was_superseded(): void
    {
        $post = $this->mapping();

        app(DepartmentPostMappingService::class)->supersede($post, [
            'class_level' => 'I',
            'pay_scale_id' => $this->level13->id,
            'government_order_number' => 'F-45/2026',
            'government_order_date' => '2026-08-01',
            'effective_from' => '2026-09-01',
        ], $this->admin);

        // Editing the mapping now in force must not trip the name rule against the
        // superseded row.
        $inForce = DepartmentPost::query()->inForce()->sole();

        // No order row: the mapping already carries F-45/2026 from the
        // supersession, and re-posting it would collide with itself. Editing the
        // terms does not re-cite the order.
        $this->actingAs($this->admin)->put(
            route('admin.department-posts.update', $inForce),
            $this->payload(['effective_from' => '2026-09-01', 'orders' => [[]]]),
        )->assertSessionHasNoErrors();
    }

    public function test_an_order_taking_effect_before_the_one_it_replaces_is_refused(): void
    {
        $post = $this->mapping();

        $this->actingAs($this->admin)->post(route('admin.department-posts.supersede', $post), [
            'class_level' => 'I',
            'government_order_number' => 'F-45/2025',
            'government_order_date' => '2025-12-01',
            'effective_from' => '2025-12-01',
        ])->assertSessionHasErrors('effective_from');

        $this->assertSame(1, DepartmentPost::query()->count());
        $this->assertTrue($post->fresh()?->isInForce());
    }

    public function test_a_mapping_already_superseded_cannot_be_superseded_again(): void
    {
        $post = $this->mapping();
        $service = app(DepartmentPostMappingService::class);

        $service->supersede($post, [
            'class_level' => 'I', 'government_order_number' => 'F-45/2026',
            'government_order_date' => '2026-08-01', 'effective_from' => '2026-09-01',
        ], $this->admin);

        $this->expectException(RuntimeException::class);

        // The chain must stay in order: the one in force is the one to replace.
        $service->supersede($post->fresh(), [
            'class_level' => 'I', 'government_order_number' => 'F-99/2026',
            'government_order_date' => '2026-08-10', 'effective_from' => '2026-10-01',
        ], $this->admin);
    }

    public function test_a_supersession_requires_its_own_order_number_and_date(): void
    {
        $post = $this->mapping();

        $this->actingAs($this->admin)->post(route('admin.department-posts.supersede', $post), [
            'class_level' => 'I',
            'effective_from' => '2026-09-01',
        ])->assertSessionHasErrors(['government_order_number', 'government_order_date']);
    }

    // ------------------------------------------------------------------
    // The screens
    // ------------------------------------------------------------------

    public function test_the_details_screen_shows_every_order_on_the_mapping(): void
    {
        $post = $this->mapping();
        $service = app(DepartmentPostMappingService::class);
        $service->recordOrder($post, 'F-31/2026', Carbon::parse('2026-04-15'), $this->pdf('second.pdf'), $this->admin);

        $this->actingAs($this->admin)->get(route('admin.department-posts.show', $post))
            ->assertOk()
            ->assertSee('F-12/2026')
            ->assertSee('F-31/2026')
            ->assertSee('second.pdf')
            ->assertSee('Level-14');
    }

    public function test_the_details_screen_shows_the_chain_after_a_supersession(): void
    {
        $post = $this->mapping();

        $replacement = app(DepartmentPostMappingService::class)->supersede($post, [
            'class_level' => 'I', 'pay_scale_id' => $this->level13->id,
            'government_order_number' => 'F-45/2026', 'government_order_date' => '2026-08-01',
            'effective_from' => '2026-09-01',
        ], $this->admin);

        $this->actingAs($this->admin)->get(route('admin.department-posts.show', $replacement))
            ->assertOk()
            ->assertSee('F-45/2026')
            // The version it replaced is on the page, which is what makes the chain
            // readable from either end.
            ->assertSee('F-12/2026');
    }

    public function test_an_order_of_another_mapping_is_not_reachable_through_this_one(): void
    {
        $post = $this->mapping();
        $order = app(DepartmentPostMappingService::class)
            ->recordOrder($post, 'F-31/2026', Carbon::parse('2026-04-15'), $this->pdf(), $this->admin);

        $other = DepartmentPost::query()->create([
            'department_id' => $this->department->id,
            'name' => 'Deputy Director',
            'class_level' => 'I',
            'effective_from' => '2026-01-01',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.department-posts.documents.download', [$other, $order]))
            ->assertNotFound();
    }

    /**
     * A replaced mapping cannot sanction a new promotion, but must remain on
     * record for the ones it already sanctioned.
     */
    public function test_a_superseded_mapping_is_no_longer_offered_to_a_pre_dpc(): void
    {
        $post = $this->mapping();

        // The post is chosen when the Department Admin opens the Pre-DPC (A1,
        // 2026-10-26), so that is the screen that offers it.
        $departmentAdmin = User::factory()->assignedTo($this->department, Role::DepartmentAdmin)->create();

        $this->actingAs($departmentAdmin)->get(route('department.allotments.pre-dpc.create'))
            ->assertOk()
            ->assertSee('value="'.$post->id.'"', false)
            ->assertSee('Joint Director');

        app(DepartmentPostMappingService::class)->supersede($post, [
            'class_level' => 'I', 'pay_scale_id' => $this->level13->id,
            'government_order_number' => 'F-45/2026', 'government_order_date' => '2026-08-01',
            'effective_from' => '2026-09-01',
        ], $this->admin);

        // The replacement carries the same name, so the *name* is still offered —
        // what matters is that the superseded row's id is not among the options.
        $offered = DepartmentPost::query()->inForce()->where('department_id', $this->department->id)->pluck('id');

        $this->assertFalse($offered->contains($post->id), 'The superseded mapping must not be selectable.');
        $this->assertSame(1, $offered->count());
        // Read the post list itself: the page has other lists (departments) whose
        // option values can be the same number.
        $page = (string) $this->actingAs($departmentAdmin)->get(route('department.allotments.pre-dpc.create'))->getContent();
        preg_match('#<select id="promotion_to_post_id".*?</select>#s', $page, $postList);
        $this->assertNotEmpty($postList, 'The post list is on the page.');
        $this->assertStringNotContainsString('value="'.$post->id.'"', $postList[0]);
    }

    public function test_only_the_administrator_reaches_any_of_it(): void
    {
        $post = $this->mapping();
        $order = app(DepartmentPostMappingService::class)
            ->recordOrder($post, 'F-31/2026', Carbon::parse('2026-04-15'), $this->pdf(), $this->admin);

        $officer = User::factory()->create();
        $officer->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($officer)->get(route('admin.department-posts.show', $post))->assertForbidden();
        $this->actingAs($officer)->get(route('admin.department-posts.supersede.form', $post))->assertForbidden();
        $this->actingAs($officer)
            ->get(route('admin.department-posts.documents.download', [$post, $order]))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Who maps posts, and the Authorized Officer's requests (2026-11-03)
    // ------------------------------------------------------------------

    /**
     * "Post Mapping Admin bhi kar sakta hai ... direct without approval sirf DA kar
     * sakta hai": the Department Admin and the Administrator save directly, the
     * Authorized Officer's save is a request, and the Administrative Head reads.
     */
    public function test_who_maps_posts_directly_who_requests_and_who_reads(): void
    {
        $post = $this->mapping();
        [$ao, $ah, $da] = $this->officers();

        foreach ([[$da, 'department.posts'], [$this->admin, 'admin.department-posts']] as [$user, $prefix]) {
            $this->actingAs($user)->get(route($prefix.'.index'))->assertOk()
                ->assertSee(route($prefix.'.create'), false)
                ->assertDontSee('id="post-mapping-read-only"', false)
                ->assertDontSee('id="post-mapping-needs-approval"', false);
            $this->actingAs($user)->get(route($prefix.'.edit', $post))->assertOk();
        }

        $this->actingAs($ao)->get(route('post-mapping.index'))->assertOk()
            ->assertSee(route('post-mapping.create'), false)
            ->assertSee('id="post-mapping-needs-approval"', false);
        $this->actingAs($ao)->get(route('post-mapping.supersede.form', $post))->assertOk();

        $this->actingAs($ah)->get(route('post-mapping.index'))->assertOk()
            ->assertSee('id="post-mapping-read-only"', false)
            ->assertDontSee(route('post-mapping.create'), false);
        $this->actingAs($ah)->post(route('post-mapping.store'), $this->payload(['name' => 'Director']))->assertForbidden();
        $this->actingAs($ah)->delete(route('post-mapping.destroy', $post))->assertForbidden();

        // The Department Admin's own save is immediate.
        $this->actingAs($da)->post(route('department.posts.store'), $this->payload(['name' => 'Director']))->assertSessionHasNoErrors();
        $this->assertTrue(DepartmentPost::query()->where('name', 'Director')->exists());
        $this->assertSame(0, DepartmentPostChange::query()->count());
    }

    public function test_an_officers_new_mapping_waits_for_the_department_admin_and_is_then_applied(): void
    {
        Storage::fake((string) config('dpc.documents.disk', 'local'));
        [$ao, , $da] = $this->officers();

        $this->actingAs($ao)->post(route('post-mapping.store'), $this->payload([
            'orders' => [['government_order_number' => 'F-12/2026', 'government_order_date' => '2026-01-01', 'document' => $this->pdf('order.pdf')]],
        ]))->assertRedirect(route('post-mapping.index'))->assertSessionHas('status', __('admin.department_posts.changes.sent'));

        $this->assertSame(0, DepartmentPost::query()->count(), 'nothing is mapped before approval');
        $change = DepartmentPostChange::query()->sole();
        $this->assertSame('pending', $change->status);
        $this->assertCount(1, $change->documents ?? []);

        // The Authorized Officer sees it waiting; the Department Admin is asked to decide.
        $this->actingAs($ao)->get(route('post-mapping.index'))->assertSee('id="post-mapping-pending"', false)->assertSee('Joint Director');
        $this->actingAs($da)->get(route('department.posts.index'))->assertSee(route('department.posts.changes.show', $change), false);
        $this->actingAs($da)->get(route('department.posts.changes.show', $change))->assertOk()
            ->assertSee('id="change-decide"', false)->assertSee('order.pdf');
        $this->actingAs($ao)->get(route('post-mapping.changes.show', $change))->assertOk()->assertDontSee('id="change-decide"', false);
        $this->actingAs($ao)->post(route('department.posts.changes.approve', $change))->assertForbidden();

        $this->actingAs($da)->post(route('department.posts.changes.approve', $change))->assertSessionHasNoErrors();

        $post = DepartmentPost::query()->sole();
        $this->assertSame('Joint Director', $post->name);
        $this->assertSame('F-12/2026', $this->orderNumber($post));
        $order = $post->orders()->sole();
        $this->assertTrue($order->hasDocument());
        $this->assertSame('order.pdf', $order->original_name);
        $this->assertSame($ao->id, $order->uploaded_by, 'the officer who sent the order is its uploader');
        $change->refresh();
        $this->assertSame('approved', $change->status);
        $this->assertSame($da->id, $change->decided_by);
        $this->assertSame($post->id, $change->result_post_id);
    }

    public function test_a_returned_request_changes_nothing_and_tells_the_officer_why(): void
    {
        $post = $this->mapping();
        [$ao, , $da] = $this->officers();

        $this->actingAs($ao)->put(route('post-mapping.update', $post), $this->payload(['promotion_duration_years' => 9, 'orders' => []]))
            ->assertRedirect(route('post-mapping.index'));
        $change = DepartmentPostChange::query()->sole();
        $this->assertSame(5, $post->fresh()->promotion_duration_years);

        // One open request per mapping.
        $this->actingAs($ao)->delete(route('post-mapping.destroy', $post))->assertSessionHasErrors('change');
        $this->assertSame(1, DepartmentPostChange::query()->count());

        $this->actingAs($da)->post(route('department.posts.changes.reject', $change), ['remarks' => ''])->assertSessionHasErrors('remarks');
        $this->actingAs($da)->post(route('department.posts.changes.reject', $change), ['remarks' => 'Duration is per the old order'])->assertSessionHasNoErrors();

        $this->assertSame(5, $post->fresh()->promotion_duration_years);
        $this->assertSame('rejected', $change->fresh()->status);
        $this->actingAs($ao)->get(route('post-mapping.index'))->assertSee('id="post-mapping-decided"', false)->assertSee('Duration is per the old order');
        // Decided once.
        $this->actingAs($da)->post(route('department.posts.changes.approve', $change))->assertSessionHasErrors('change');
    }

    public function test_an_officers_edit_supersession_and_withdrawal_apply_on_approval(): void
    {
        $post = $this->mapping();
        [$ao, , $da] = $this->officers();

        $this->actingAs($ao)->put(route('post-mapping.update', $post), $this->payload(['effective_from' => '2025-04-01', 'orders' => []]));
        $this->actingAs($da)->post(route('department.posts.changes.approve', DepartmentPostChange::query()->latest('id')->firstOrFail()));
        $this->assertSame('2025-04-01', $post->fresh()->effective_from?->toDateString());

        $this->actingAs($ao)->post(route('post-mapping.supersede', $post), [
            'class_level' => 'I', 'pay_scale_id' => $this->level13->id, 'promotion_duration_years' => 4,
            'government_order_number' => 'F-20/2026', 'government_order_date' => '2026-06-01', 'effective_from' => '2026-07-01',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($post->fresh()->isInForce(), 'still in force until approved');
        $this->actingAs($da)->post(route('department.posts.changes.approve', DepartmentPostChange::query()->latest('id')->firstOrFail()))->assertSessionHasNoErrors();
        $this->assertTrue($post->fresh()->isSuperseded());
        $successor = DepartmentPost::query()->where('name', 'Joint Director')->whereNull('effective_to')->sole();
        $this->assertSame('2026-07-01', $successor->effective_from?->toDateString());

        $this->actingAs($ao)->delete(route('post-mapping.destroy', $successor));
        $this->assertNotNull(DepartmentPost::query()->find($successor->id));
        $this->actingAs($da)->post(route('department.posts.changes.approve', DepartmentPostChange::query()->latest('id')->firstOrFail()));
        $this->assertNull(DepartmentPost::query()->find($successor->id));
    }

    public function test_another_departments_admin_cannot_see_or_decide_a_request(): void
    {
        [$ao] = $this->officers();
        $this->actingAs($ao)->post(route('post-mapping.store'), $this->payload());
        $change = DepartmentPostChange::query()->sole();

        $other = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'active']);
        $foreign = User::factory()->assignedTo($other, Role::DepartmentAdmin)->create();

        $this->actingAs($foreign)->get(route('department.posts.index'))->assertDontSee('id="post-mapping-pending"', false);
        $this->actingAs($foreign)->get(route('department.posts.changes.show', $change))->assertForbidden();
        $this->actingAs($foreign)->post(route('department.posts.changes.approve', $change))->assertForbidden();
        $this->assertSame(0, DepartmentPost::query()->count());
    }

    public function test_the_administrator_filters_the_list_by_department(): void
    {
        $this->mapping();
        $other = Department::query()->create(['code' => 'SE', 'name' => 'School Education', 'status' => 'active']);
        $this->actingAs($this->admin)->post(route('admin.department-posts.store'), $this->payload(['department_id' => $other->id, 'name' => 'Principal']))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('admin.department-posts.index'))->assertOk()
            ->assertSee('id="post-mapping-department-filter"', false)->assertSee('Joint Director')->assertSee('Principal');
        $this->actingAs($this->admin)->get(route('admin.department-posts.index', ['department_id' => $other->id]))->assertOk()
            ->assertSee('Principal')->assertDontSee('Joint Director');

        $da = User::factory()->assignedTo($this->department, Role::DepartmentAdmin)->create();
        $this->actingAs($da)->get(route('department.posts.index'))->assertDontSee('id="post-mapping-department-filter"', false);
    }

    /**
     * @return array{0: User, 1: User, 2: User} the department's AO, AH and Department Admin
     */
    private function officers(): array
    {
        return [
            User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create(),
            User::factory()->assignedTo($this->department->id, Role::AdministrativeHead)->create(),
            User::factory()->assignedTo($this->department, Role::DepartmentAdmin)->create(),
        ];
    }
}
