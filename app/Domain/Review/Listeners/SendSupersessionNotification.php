<?php

declare(strict_types=1);

namespace App\Domain\Review\Listeners;

use App\Domain\Allotment\Services\ProposalAllotmentService;
use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Notification\Services\NotificationRecipientResolver;
use App\Domain\Notification\Templates\MessageTemplates;
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

    public function __construct(
        private readonly SmsSender $sms,
        private readonly NotificationRecipientResolver $recipients,
        private readonly MessageTemplates $templates,
    ) {}

    public function handle(DpcSupersededByReview $event): void
    {
        if ($event->departmentId === null) {
            return;
        }

        // The Administrator's wording (2026-11-12).
        $values = [
            'original' => $event->originalDpcCode ?? $event->originalProposalUuid,
            'review' => $event->reviewDpcCode ?? $event->reviewProposalUuid,
            'review_number' => $event->reviewNumber,
        ];
        $sms = $this->templates->sms('review.superseded', $values);
        $mailText = $this->templates->email('review.superseded', $values);

        // **TD-20, closed 2026-08-19 under the final-phase Freeze Exception.**
        // This query was one of three copies of "who should be told". Two of them
        // lived in frozen milestones, so the shared resolver was written for the
        // in-app channel first and the copies were left in place; now they are
        // repointed at it. Behaviour is identical — same roles, same department
        // scope, same exclusion of deactivated accounts — which is why this could be
        // done inside a freeze at all.
        // The review's allotted AO and AH (2026-10-26), not the whole department.
        $review = DpcProposal::query()->where('uuid', $event->reviewProposalUuid)->first();
        $recipients = $this->recipients->resolve(
            null,
            [],
            $review === null ? [] : app(ProposalAllotmentService::class)->recipientIds($review, [Role::AuthorizedOfficer->value, Role::AdministrativeHead->value]),
        );

        foreach ($recipients as $recipient) {
            try {
                if ($sms !== null && $recipient->mobile !== null) {
                    $this->sms->send($recipient->mobile, $sms['body'], $sms['dlt_template_id']);
                }

                if ($mailText !== null && $recipient->email !== null) {
                    $email = $recipient->email;
                    Mail::raw($mailText['body'], function ($mail) use ($email, $mailText): void {
                        $mail->to($email)->subject($mailText['subject']);
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
