<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Jobs;

use App\Domain\CandidateVerification\Models\VerificationNotification;
use App\Domain\CandidateVerification\Services\VerificationNotifier;
use App\Domain\Notification\Templates\MessageTemplates;
use App\Integrations\Contracts\SmsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Sends one recorded verification message and records what became of it. Retried by
 * the queue; failed for good after the last attempt. A message sent once is never
 * sent again, whatever re-runs the job.
 */
final class SendVerificationNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** @param array<string, string> $values */
    public function __construct(public readonly int $notificationId, public readonly array $values) {}

    public function handle(MessageTemplates $templates, SmsSender $sms): void
    {
        $notification = VerificationNotification::query()->find($this->notificationId);

        if ($notification === null || $notification->status !== VerificationNotification::QUEUED) {
            return;
        }

        $notification->increment('attempts');
        $key = 'candidate_verification.'.$notification->kind;

        try {
            if ($notification->channel === VerificationNotifier::SMS) {
                $message = $templates->sms($key, $this->values) ?? throw new RuntimeException('The SMS template ['.$key.'] is switched off.');
                $sms->send((string) $notification->destination, $message['body'], $message['dlt_template_id']);
            } else {
                $message = $templates->email($key, $this->values) ?? throw new RuntimeException('The e-mail template ['.$key.'] is switched off.');
                Mail::raw($message['body'], fn ($mail) => $mail->to((string) $notification->destination)->subject($message['subject']));
            }
        } catch (Throwable $e) {
            $notification->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();

            throw $e;
        }

        $notification->forceFill(['status' => VerificationNotification::SENT, 'sent_at' => now(), 'last_error' => null])->save();
    }

    public function failed(Throwable $e): void
    {
        VerificationNotification::query()->whereKey($this->notificationId)->where('status', VerificationNotification::QUEUED)
            ->update(['status' => VerificationNotification::FAILED, 'last_error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()]);

        Log::warning('A candidate verification message could not be sent.', ['notification_id' => $this->notificationId, 'error' => $e->getMessage()]);
    }
}
