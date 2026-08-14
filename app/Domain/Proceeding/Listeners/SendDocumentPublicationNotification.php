<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Listeners;

use App\Domain\Authentication\Models\User;
use App\Domain\Proceeding\Events\ProceedingDocumentsPublished;
use App\Enums\Role;
use App\Integrations\Contracts\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells the department its DPC result is available — M7 Phase 4, PPT S26.
 *
 * Follows `SendWorkflowNotification`'s doctrine exactly: queued, `afterCommit`,
 * best-effort **per recipient**, and incapable of failing the action that
 * triggered it. One unreachable mobile must not stop the next person being
 * told, and none of it may surface as an error to the Commission.
 *
 * The recipient query is deliberately duplicated rather than shared:
 * `SendWorkflowNotification` belongs to frozen M6, and extracting a common
 * resolver would be a functional change to it. Recorded as technical debt —
 * the extraction is the right move the next time that freeze is opened.
 */
class SendDocumentPublicationNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly SmsSender $sms) {}

    public function handle(ProceedingDocumentsPublished $event): void
    {
        if ($event->departmentId === null) {
            return;
        }

        $message = 'ODPC: the result of DPC '.($event->dpcCode ?? $event->proceedingUuid)
            .' has been approved by CGPSC and the proceedings and promotion list are now available on the portal.';

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
                        $mail->to($email)->subject('ODPC Portal — DPC Result Available');
                    });
                }
            } catch (Throwable $e) {
                Log::warning('Document publication notification delivery failed.', [
                    'proceeding_uuid' => $event->proceedingUuid,
                    'recipient_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
