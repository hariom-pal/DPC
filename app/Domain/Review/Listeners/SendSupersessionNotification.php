<?php

declare(strict_types=1);

namespace App\Domain\Review\Listeners;

use App\Domain\Authentication\Models\User;
use App\Domain\Review\Events\DpcSupersededByReview;
use App\Enums\Role;
use App\Integrations\Contracts\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells the department that one of its DPCs has been superseded by a Review —
 * M8 Phase 3.
 *
 * This is the notification that matters most in the milestone: a department
 * holding a signed promotion list needs to know it is no longer the operative
 * one, and it needs to know *which* list replaces it.
 *
 * Follows the doctrine established in M6 and reused in M7: queued,
 * `afterCommit`, best-effort **per recipient**, and incapable of failing the
 * approval that triggered it.
 *
 * The recipient query is duplicated from `SendWorkflowNotification` for the
 * third time — see **TD-20**. Extracting a shared resolver is a functional
 * change to frozen M6, so it waits for that freeze to open; the case for doing
 * it is now stronger than the case for another copy.
 */
class SendSupersessionNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly SmsSender $sms) {}

    public function handle(DpcSupersededByReview $event): void
    {
        if ($event->departmentId === null) {
            return;
        }

        $message = 'ODPC: DPC '.($event->originalDpcCode ?? $event->originalProposalUuid)
            .' has been superseded by Review DPC '.($event->reviewDpcCode ?? $event->reviewProposalUuid)
            .' (review '.$event->reviewNumber.'). The earlier promotion list is no longer the operative one.';

        $recipients = User::query()
            ->where('is_active', true)
            ->where('department_id', $event->departmentId)
            ->role([Role::AuthorizedOfficer->value, Role::AdministrativeHead->value])
            ->get();

        foreach ($recipients as $recipient) {
            try {
                if ($recipient->mobile !== null) {
                    $this->sms->send($recipient->mobile, $message);
                }

                if ($recipient->email !== null) {
                    $email = $recipient->email;
                    Mail::raw($message, function ($mail) use ($email): void {
                        $mail->to($email)->subject('ODPC Portal — DPC Superseded by Review');
                    });
                }
            } catch (Throwable $e) {
                Log::warning('Supersession notification delivery failed.', [
                    'chain_uuid' => $event->chainUuid,
                    'recipient_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
