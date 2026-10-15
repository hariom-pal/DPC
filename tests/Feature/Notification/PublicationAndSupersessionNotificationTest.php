<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Notification\Models\UserNotification;
use App\Domain\Proceeding\Events\ProceedingDocumentsPublished;
use App\Domain\Review\Events\DpcSupersededByReview;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two events that are **not** workflow transitions — M11.
 *
 * Publication (M7 Phase 4) and supersession (M8 Phase 3) each got an event of
 * their own precisely because neither changes a state: reusing
 * `WorkflowTransitioned` would have the ledger claim a transition that never
 * happened. So each needs its own in-app listener, and this asserts both exist and
 * neither is silent.
 *
 * The supersession wording is the interesting one: **BD-11 is unresolved**, so the
 * notification may say which paper now governs and must say nothing about what
 * happens to an officer already promoted. A test pins that silence, because it is
 * the kind of thing a later "helpful" edit would remove.
 */
class PublicationAndSupersessionNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $ao;

    private User $ah;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::query()->create([
            'code' => 'SE', 'name' => 'School Education Department', 'status' => 'active',
        ]);

        $this->ao = User::factory()->assignedTo($this->department->id, Role::AuthorizedOfficer)->create();

        $this->ah = User::factory()->assignedTo($this->department->id, Role::AdministrativeHead)->create();
    }

    public function test_publication_notifies_both_departmental_roles(): void
    {
        ProceedingDocumentsPublished::dispatch(
            'proceeding-uuid-1', 'proposal-uuid-1', 'run-uuid-1',
            ['doc-uuid-1', 'doc-uuid-2'], $this->department->id, 'DPC-2026-SE-0001',
        );

        $this->assertSame(2, UserNotification::query()->count());

        $notification = UserNotification::query()->firstOrFail();

        $this->assertSame(
            'The signed documents for DPC DPC-2026-SE-0001 have been released',
            $notification->title,
        );
        $this->assertStringContainsString('2 document(s) published', (string) $notification->body);
    }

    /** A DPC with no code yet still produces a legible title rather than "DPC ". */
    public function test_publication_without_a_dpc_code_still_reads_correctly(): void
    {
        ProceedingDocumentsPublished::dispatch(
            'proceeding-uuid-2', 'proposal-uuid-2', null, ['doc-uuid-3'], $this->department->id, null,
        );

        $this->assertSame(
            'The signed documents for your DPC have been released',
            UserNotification::query()->firstOrFail()->title,
        );
    }

    public function test_supersession_names_both_dpc_codes(): void
    {
        $this->supersede();

        $notification = UserNotification::query()->firstOrFail();

        $this->assertSame(
            'DPC DPC-2026-SE-0001 has been superseded by Review DPC DPC-2026-SE-0002',
            $notification->title,
        );
        $this->assertStringContainsString('DPC-2026-SE-0001', (string) $notification->body);
    }

    /**
     * **BD-11 is unresolved, so the notification asserts nothing about the
     * promotion.**
     *
     * The three plausible answers — the promotion stands and only the paper is
     * corrected; it is withdrawn from the date of the Review; it is withdrawn
     * retrospectively with recovery of pay — differ in what they do to a serving
     * officer's substantive rank and pay. Telling a department any of them before
     * CGPSC rules would be inventing service law.
     */
    public function test_supersession_says_nothing_about_what_happens_to_the_promotion(): void
    {
        $this->supersede();

        $notification = UserNotification::query()->firstOrFail();
        $text = $notification->title.' '.$notification->body;

        foreach ([
            'withdrawn', 'withdraw', 'cancelled', 'reverted', 'revert',
            'arrears', 'recovery', 'demoted', 'invalid', 'void',
        ] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $text,
                "the supersession notification must not assert '{$forbidden}' while BD-11 is open",
            );
        }

        // What it *does* say: which list has stopped being the current one.
        $this->assertStringContainsString('no longer the current one', (string) $notification->body);
    }

    public function test_neither_event_notifies_another_department(): void
    {
        $other = Department::query()->create([
            'code' => 'HE', 'name' => 'Higher Education', 'status' => 'active',
        ]);
        $outsider = User::factory()->assignedTo($other->id, Role::AuthorizedOfficer)->create();

        ProceedingDocumentsPublished::dispatch(
            'p-uuid', 'pr-uuid', null, ['d-uuid'], $this->department->id, 'DPC-2026-SE-0001',
        );
        $this->supersede();

        $this->assertSame(
            0,
            UserNotification::query()->where('user_id', $outsider->id)->count(),
        );
    }

    private function supersede(): void
    {
        DpcSupersededByReview::dispatch(
            'chain-uuid-1', 1,
            'original-proposal-uuid', 'DPC-2026-SE-0001',
            'review-proposal-uuid', 'DPC-2026-SE-0002',
            $this->department->id,
        );
    }
}
