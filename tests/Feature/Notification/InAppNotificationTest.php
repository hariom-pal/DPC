<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Notification\Models\UserNotification;
use App\Domain\Notification\Services\NotificationCentre;
use App\Domain\Shared\Events\WorkflowTransitioned;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * In-app notifications — M11.
 *
 * **Why the channel exists.** Every workflow notification in this system went out
 * by SMS and e-mail only. The government SMS gateway is not procured (**TD-6**)
 * and `FEATURE_NOTIFICATIONS` defaults to off, so in a demo — and on the day the
 * system goes live before its gateway does — **nobody was actually notified of
 * anything**. This channel needs no gateway.
 *
 * **How it was added matters as much as what it does.** M6's
 * `SendWorkflowNotification` is untouched: this is a *second* listener on the same
 * event, which is AD-20's pattern (behaviour is added to a completed milestone by
 * decoration, never by edit) applied to an event subscriber.
 */
class InAppNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $ah;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::query()->create([
            'code' => 'SE', 'name' => 'School Education Department', 'status' => 'active',
        ]);

        $this->ao = $this->user('Shri Anil Kumar Verma', Role::AuthorizedOfficer, $this->department);
        $this->ah = $this->user('Smt. Sunita Rathore', Role::AdministrativeHead, $this->department);
        // A Commission Member holds no department, which is exactly why the event
        // carries explicit user ids beside department-scoped roles.
        $this->member = $this->user('Shri Brijesh Kumar Pandey', Role::Member, null);
    }

    // ------------------------------------------------------------------
    // The listener
    // ------------------------------------------------------------------

    public function test_a_transition_writes_an_in_app_notification_to_each_recipient(): void
    {
        WorkflowTransitioned::dispatch(
            'pre_dpc', 'submit', 'submitted', $this->department->id, ['administrative_head'],
        );

        $this->assertSame(1, UserNotification::query()->count());

        $notification = UserNotification::query()->firstOrFail();

        $this->assertSame($this->ah->id, $notification->user_id);
        // The title is a sentence, not the SMS's "dpc_cgpsc was 'allot'".
        $this->assertSame('A Pre-DPC proposal is awaiting your approval', $notification->title);
        $this->assertStringContainsString('pre_dpc · submit → submitted', (string) $notification->body);
        $this->assertTrue($notification->isUnread());
    }

    /**
     * A CGPSC Member has no department, so no role-plus-department query can reach
     * them. Losing that recipient set is the failure this asserts against.
     */
    public function test_a_named_user_outside_any_department_is_notified(): void
    {
        WorkflowTransitioned::dispatch(
            'dpc_cgpsc', 'allot', 'allotted', $this->department->id,
            ['authorized_officer', 'administrative_head'], null, [$this->member->id],
        );

        $recipients = UserNotification::query()->pluck('user_id')->all();

        sort($recipients);
        $expected = [$this->ao->id, $this->ah->id, $this->member->id];
        sort($expected);

        $this->assertSame($expected, $recipients);
    }

    public function test_remarks_are_carried_because_they_say_what_to_fix(): void
    {
        WorkflowTransitioned::dispatch(
            'pre_dpc', 'return', 'returned', $this->department->id, ['authorized_officer'],
            'The 2016 CR grade is missing.',
        );

        $notification = UserNotification::query()->firstOrFail();

        $this->assertSame('Your Pre-DPC proposal has been returned for correction', $notification->title);
        $this->assertStringContainsString('The 2016 CR grade is missing.', (string) $notification->body);
    }

    /**
     * An event nobody has written a phrasing for still produces a legible line.
     *
     * A `match` that threw would mean a new milestone's transition could not be
     * notified until this file was edited.
     */
    public function test_an_unknown_event_still_produces_a_readable_title(): void
    {
        WorkflowTransitioned::dispatch(
            'some_future_workflow', 'do_a_new_thing', 'done', $this->department->id, ['administrative_head'],
        );

        $this->assertSame(
            'Some future workflow — Do a new thing (now Done)',
            UserNotification::query()->firstOrFail()->title,
        );
    }

    public function test_a_deactivated_account_is_not_notified(): void
    {
        $this->ah->forceFill(['is_active' => false])->save();

        WorkflowTransitioned::dispatch(
            'pre_dpc', 'submit', 'submitted', $this->department->id, ['administrative_head'],
        );

        $this->assertSame(0, UserNotification::query()->count());
    }

    public function test_a_transition_with_no_recipients_writes_nothing(): void
    {
        WorkflowTransitioned::dispatch('pre_dpc', 'submit', 'submitted', null, []);

        $this->assertSame(0, UserNotification::query()->count());
    }

    /**
     * The channel is switched by its own configuration, not by
     * `FEATURE_NOTIFICATIONS`.
     *
     * That flag gates *external* delivery, which needs a procured gateway. Gating
     * an in-app record behind it would switch off the only channel that works.
     */
    public function test_the_channel_has_its_own_switch(): void
    {
        Config::set('dpc.notifications.in_app.enabled', false);

        WorkflowTransitioned::dispatch(
            'pre_dpc', 'submit', 'submitted', $this->department->id, ['administrative_head'],
        );

        $this->assertSame(0, UserNotification::query()->count());
    }

    /**
     * A transition must never fail because a notification did — the M6 rule, and
     * the defect it was written for.
     */
    public function test_a_failure_writing_the_feed_never_breaks_the_transition(): void
    {
        // A title column that cannot accept the value forces the write to throw.
        Schema::drop('user_notifications');

        WorkflowTransitioned::dispatch(
            'pre_dpc', 'submit', 'submitted', $this->department->id, ['administrative_head'],
        );

        // Reaching this line is the assertion: the dispatch did not raise.
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // The screen
    // ------------------------------------------------------------------

    public function test_the_feed_shows_only_the_signed_in_users_notifications(): void
    {
        $mine = $this->notify($this->ah, 'Yours to approve');
        $theirs = $this->notify($this->ao, 'Not yours at all');

        $this->actingAs($this->ah)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Yours to approve')
            ->assertDontSee('Not yours at all');

        unset($mine, $theirs);
    }

    public function test_the_unread_filter_narrows_the_feed(): void
    {
        $read = $this->notify($this->ah, 'Already seen');
        $read->forceFill(['read_at' => now()])->save();

        $this->notify($this->ah, 'Still waiting');

        $response = $this->actingAs($this->ah)
            ->get(route('notifications.index', ['unread' => 1]))
            ->assertOk();

        // Asserted against the view's own data rather than the rendered page: the
        // header bell deliberately shows the most *recent* few whatever their read
        // state, so "Already seen" is legitimately on this page — in the dropdown,
        // not in the filtered list. A whole-page assertDontSee would be checking
        // the wrong thing and would fail for the right reason.
        $titles = $response->viewData('notifications')->pluck('title')->all();

        $this->assertSame(['Still waiting'], $titles);
    }

    public function test_the_bell_shows_the_unread_count_on_any_authenticated_screen(): void
    {
        $this->notify($this->ah, 'One');
        $this->notify($this->ah, 'Two');

        // The dashboard, not the notifications screen — the point of the composer
        // is that the bell reaches screens that know nothing about it.
        $this->actingAs($this->ah)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('2 unread')
            ->assertSee('One');
    }

    public function test_marking_one_read_follows_where_it_points(): void
    {
        $notification = $this->notify($this->ah, 'Open me', '/pre-dpc');

        $this->actingAs($this->ah)
            ->post(route('notifications.read', $notification))
            ->assertRedirect('/pre-dpc');

        $this->assertNotNull($notification->fresh()->read_at);
    }

    /**
     * A stored absolute URL would make this endpoint an open redirect.
     */
    public function test_an_external_url_is_not_followed(): void
    {
        $notification = $this->notify($this->ah, 'Suspicious', 'https://evil.example/steal');

        $this->actingAs($this->ah)
            ->post(route('notifications.read', $notification))
            ->assertRedirect(route('notifications.index'));
    }

    /**
     * Reading somebody else's notification would disclose that a DPC exists and
     * what stage it has reached.
     */
    public function test_another_users_notification_cannot_be_marked_read(): void
    {
        $notification = $this->notify($this->ao, 'Not yours');

        $this->actingAs($this->ah)
            ->post(route('notifications.read', $notification))
            ->assertForbidden();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_mark_all_read_clears_only_this_users_unread(): void
    {
        $this->notify($this->ah, 'Mine one');
        $this->notify($this->ah, 'Mine two');
        $theirs = $this->notify($this->ao, 'Theirs');

        $this->actingAs($this->ah)->post(route('notifications.read-all'))
            ->assertRedirect(route('notifications.index'));

        $this->assertSame(0, UserNotification::query()->where('user_id', $this->ah->id)->unread()->count());
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_the_feed_requires_authentication(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
    }

    /**
     * The count is cached per user for the header badge, so a write has to drop it
     * — otherwise the bell stays stale on precisely the request that should light
     * it up.
     */
    public function test_a_new_notification_invalidates_the_cached_count(): void
    {
        $centre = app(NotificationCentre::class);

        $this->assertSame(0, $centre->unreadCount($this->ah));

        WorkflowTransitioned::dispatch(
            'pre_dpc', 'submit', 'submitted', $this->department->id, ['administrative_head'],
        );

        $this->assertSame(1, $centre->unreadCount($this->ah));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function user(string $name, Role $role, ?Department $department): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'department_id' => $department?->id,
        ]);
        $user->assignRole($role->value);

        return $user;
    }

    private function notify(User $user, string $title, ?string $url = null): UserNotification
    {
        /** @var UserNotification $notification */
        $notification = UserNotification::query()->create([
            'user_id' => $user->id,
            'event' => 'test.event',
            'title' => $title,
            'body' => 'test · body',
            'url' => $url,
            'occurred_at' => now(),
        ]);

        return $notification;
    }
}
