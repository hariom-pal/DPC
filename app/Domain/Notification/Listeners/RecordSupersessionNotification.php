<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Services\NotificationCentre;
use App\Domain\Review\Events\DpcSupersededByReview;
use App\Enums\Role;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The in-app copy of "a Review has superseded one of your DPCs" — M11.
 *
 * **What this notification may and may not say is constrained by BD-11.** Until
 * CGPSC rules, the system records the supersession, names both DPC codes, and
 * **asserts nothing about the promotion itself** — no withdrawal, no reverted
 * rank, no arrears. The three plausible answers differ in what they do to a
 * serving officer's substantive rank and pay, so this text says which paper now
 * governs and stops there. That is the same abstention M8's document and SMS text
 * already observe, and it is the reason the wording below is so careful.
 *
 * M8's `SendSupersessionNotification` is untouched.
 */
class RecordSupersessionNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly NotificationCentre $centre) {}

    public function handle(DpcSupersededByReview $event): void
    {
        try {
            $original = $event->originalDpcCode ?? 'the original DPC';
            $review = $event->reviewDpcCode ?? 'a Review DPC';

            $this->centre->notify(
                $event->departmentId,
                [Role::AuthorizedOfficer->value, Role::AdministrativeHead->value],
                [],
                [
                    'workflow' => 'dpc_review',
                    'event' => 'superseded',
                    'title' => "DPC {$original} has been superseded by Review DPC {$review}",
                    // Deliberately factual and deliberately silent on consequence
                    // (BD-11). "Stop relying on" is what the department must
                    // actually do; what happens to an officer already promoted is
                    // not this system's to state yet.
                    'body' => "Review number {$event->reviewNumber} · chain {$event->chainUuid} · "
                        ."the promotion list issued under {$original} is no longer the current one.",
                ],
            );
        } catch (Throwable $e) {
            Log::warning('In-app supersession notification could not be recorded.', [
                'chain_uuid' => $event->chainUuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
