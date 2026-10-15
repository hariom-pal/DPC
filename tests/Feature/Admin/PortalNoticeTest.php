<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Master\Models\PortalNotice;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Suggestion 6: the landing page's notice board is master data, and falls back to
 * the catalogue so it is never an empty box.
 */
class PortalNoticeTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function notice(array $overrides = []): PortalNotice
    {
        return PortalNotice::query()->create(array_replace([
            'title_en' => 'Circulation DPC is now available',
            'title_hi' => 'परिभ्रमण डी.पी.सी. अब उपलब्ध',
            'body_en' => 'Departments can resolve a reserved promotion online.',
            'body_hi' => 'विभाग आरक्षित पदोन्नति का निराकरण ऑनलाइन कर सकते हैं।',
            'published_from' => Carbon::today()->subDay(),
            'status' => 'active',
        ], $overrides));
    }

    public function test_the_landing_page_falls_back_to_the_catalogue_when_nothing_is_published(): void
    {
        // The four the language file has always carried.
        $this->get(route('home'))->assertOk()->assertSee('Circulation DPC is now available');
    }

    public function test_a_published_notice_replaces_the_catalogue_outright(): void
    {
        $this->notice(['title_en' => 'Only this one', 'title_hi' => 'केवल यही']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Only this one')
            // A mixture of master and catalogue would be impossible to reason about.
            ->assertDontSee('Review DPC available to all departments');
    }

    public function test_a_notice_outside_its_dates_is_not_shown(): void
    {
        $this->notice(['title_en' => 'Future notice', 'published_from' => Carbon::today()->addWeek()]);
        $this->notice(['title_en' => 'Expired notice', 'published_until' => Carbon::today()->subDay()]);

        $response = $this->get(route('home'))->assertOk();
        $response->assertDontSee('Future notice');
        $response->assertDontSee('Expired notice');
    }

    public function test_a_null_end_date_means_until_further_notice(): void
    {
        $this->notice(['title_en' => 'Standing notice', 'published_until' => null]);

        $this->get(route('home'))->assertOk()->assertSee('Standing notice');
    }

    public function test_the_hindi_text_is_used_on_a_hindi_screen(): void
    {
        $this->notice(['title_en' => 'English title', 'title_hi' => 'हिन्दी शीर्षक']);

        $this->withSession(['locale' => 'hi'])->get(route('home'))
            ->assertOk()
            ->assertSee('हिन्दी शीर्षक', escape: false);
    }

    /**
     * A notice is often issued in English first. Refusing to publish it until the
     * translation exists means it is not published.
     */
    public function test_a_notice_with_no_hindi_shows_its_english_on_a_hindi_screen(): void
    {
        $this->notice(['title_en' => 'Untranslated notice', 'title_hi' => null, 'body_hi' => null]);

        $this->withSession(['locale' => 'hi'])->get(route('home'))
            ->assertOk()
            ->assertSee('Untranslated notice');
    }

    public function test_the_administrator_publishes_edits_and_withdraws_a_notice(): void
    {
        $this->actingAs($this->admin)->post(route('admin.portal-notices.store'), [
            'title_en' => 'A new notice',
            'body_en' => 'Something has changed.',
            'published_from' => Carbon::today()->toDateString(),
            'status' => 'active',
        ])->assertRedirect(route('admin.portal-notices.index'));

        $notice = PortalNotice::query()->sole();
        $this->assertSame('A new notice', $notice->title_en);
        $this->assertSame($this->admin->id, $notice->created_by);

        $this->actingAs($this->admin)->put(route('admin.portal-notices.update', $notice), [
            'title_en' => 'A corrected notice',
            'body_en' => 'Something has changed.',
            'published_from' => Carbon::today()->toDateString(),
            'status' => 'active',
        ])->assertRedirect();

        $this->assertSame('A corrected notice', $notice->fresh()?->title_en);

        $this->actingAs($this->admin)->delete(route('admin.portal-notices.destroy', $notice))->assertRedirect();
        $this->get(route('home'))->assertOk()->assertDontSee('A corrected notice');
    }

    public function test_an_end_date_before_the_start_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('admin.portal-notices.store'), [
            'title_en' => 'Backwards',
            'body_en' => 'Body.',
            'published_from' => Carbon::today()->toDateString(),
            'published_until' => Carbon::today()->subDay()->toDateString(),
            'status' => 'active',
        ])->assertSessionHasErrors('published_until');
    }

    /**
     * A one-day notice has the same date on both, which is legitimate.
     */
    public function test_a_single_day_notice_is_accepted(): void
    {
        $today = Carbon::today()->toDateString();

        $this->actingAs($this->admin)->post(route('admin.portal-notices.store'), [
            'title_en' => 'One day only',
            'body_en' => 'Body.',
            'published_from' => $today,
            'published_until' => $today,
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->get(route('home'))->assertOk()->assertSee('One day only');
    }

    public function test_a_departmental_user_cannot_reach_the_notice_board(): void
    {
        $officer = User::factory()->create();
        $officer->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($officer)->get(route('admin.portal-notices.index'))->assertForbidden();
    }
}
