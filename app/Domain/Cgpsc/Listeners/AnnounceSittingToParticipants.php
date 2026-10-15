<?php

declare(strict_types=1);

namespace App\Domain\Cgpsc\Listeners;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\Events\DpcSittingScheduled;
use App\Domain\Cgpsc\ValueObjects\SittingRecipient;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcSeniorityEntry;
use App\Domain\Notification\Services\NotificationCentre;
use App\Integrations\Contracts\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tell everybody who has to attend (business clarification, 2026-09-05).
 *
 * > *Once the DPC Allottee schedules the meeting, automatic SMS and Email
 * > notifications shall be sent to: All Committee Members, All Candidates.
 * > Notification should contain: DPC Date, DPC Time, Basic Joining Instructions.*
 *
 * ### Two audiences, gathered from two places
 *
 * **Committee members** come from `dpc_committee_members`, serving rows only — a
 * member who was replaced is not attending, and telling them to would be worse
 * than telling them nothing. Their contact details live on the membership row
 * (a committee may seat somebody who has no portal account at all), so those are
 * used in preference to the linked account's.
 *
 * **Candidates** come from the Seniority List, which is where candidature is
 * defined — not from the `promotion_candidate` role, which a person keeps after
 * their DPC concludes and which would therefore reach officers this sitting has
 * nothing to do with.
 *
 * ### What actually reaches a candidate today, stated plainly
 *
 * **A Seniority List carries no mobile number and no e-mail address.** The
 * ten-field officer snapshot has no contact column, by design — it is a snapshot
 * of a service record, not a directory. So for candidates this listener writes the
 * **in-app notification** and has no channel to send SMS or e-mail on.
 *
 * That is a data gap and not a coding one, and it is not papered over: the counts
 * are logged, so "we notified 9 candidates in-app and could reach 0 by SMS" is
 * visible rather than inferred. Closing it needs a contact channel on the
 * candidate record, which is recorded as a future enhancement (candidate login by
 * mobile + OTP) rather than invented here.
 *
 * ### Failure is contained
 *
 * Scheduling must never fail because a notification did — the rule every notifier
 * in this system follows. Delivery is best-effort per recipient inside a try, and
 * the whole handler is wrapped again so a broken mail transport cannot take the
 * queue worker down with it.
 */
class AnnounceSittingToParticipants implements ShouldQueue
{
    /**
     * Dispatched only once the surrounding transaction commits, so a message can
     * never announce a sitting that was rolled back.
     */
    public bool $afterCommit = true;

    public function __construct(
        private readonly SmsSender $sms,
        private readonly NotificationCentre $centre,
    ) {}

    public function handle(DpcSittingScheduled $event): void
    {
        try {
            $proposal = $event->proposal;
            $schedule = $event->schedule;

            $message = (string) __($event->isReschedule
                ? 'notifications.sitting.sms_rescheduled'
                : 'notifications.sitting.sms_scheduled', [
                    'dpc' => $proposal->dpc_code ?? $proposal->citation(),
                    'date' => $schedule->dpc_date->format('d-m-Y'),
                    'time' => substr((string) $schedule->dpc_time, 0, 5),
                    'instructions' => (string) __('notifications.sitting.joining_instructions'),
                ]);

            $subject = (string) __($event->isReschedule
                ? 'notifications.sitting.subject_rescheduled'
                : 'notifications.sitting.subject_scheduled', [
                    'dpc' => $proposal->dpc_code ?? $proposal->citation(),
                ]);

            $members = $this->committee($proposal->id);
            $candidates = $this->candidates($proposal->id);

            $this->deliver($members->concat($candidates), $message, $subject);

            $this->recordInApp($event, $subject, $message, $members, $candidates);
        } catch (Throwable $e) {
            Log::warning('Sitting notification could not be sent.', [
                'proposal_id' => $event->proposal->id,
                'schedule_uuid' => $event->schedule->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Serving committee members, as `{user_id, name, mobile, email}` rows.
     *
     * The membership's own contact details win over the linked account's, because
     * a committee row is what the officer typed for *this* committee — an external
     * member may have an account created only so they are not duplicated next time,
     * carrying nothing else.
     *
     * @return Collection<int, SittingRecipient>
     */
    private function committee(int $proposalId): Collection
    {
        return DpcCommitteeMember::query()
            ->where('dpc_proposal_id', $proposalId)
            ->serving()
            ->with('user')
            ->get()
            ->map(fn (DpcCommitteeMember $member): SittingRecipient => new SittingRecipient(
                name: $member->name,
                userId: $member->user_id,
                mobile: $member->mobile ?? $member->user?->mobile,
                email: $member->email ?? $member->user?->email,
            ));
    }

    /**
     * The officers under consideration, from the Seniority List.
     *
     * The account is matched on employee number, which is the only handle a
     * Seniority List carries — and the same handle `PromotionCandidateProvisioner`
     * used to create the account in the first place, so the two cannot disagree.
     *
     * Neither `mobile` nor `email` is ever populated here today; see the class
     * note. The rows still travel with the account id, which is what the in-app
     * notification needs.
     *
     * @return Collection<int, SittingRecipient>
     */
    private function candidates(int $proposalId): Collection
    {
        $entries = DpcSeniorityEntry::query()
            ->where('dpc_proposal_id', $proposalId)
            ->get(['employee_number', 'name']);

        if ($entries->isEmpty()) {
            return new Collection;
        }

        $accounts = User::query()
            ->whereIn('employee_number', $entries->pluck('employee_number')->filter()->all())
            ->get(['id', 'employee_number', 'mobile', 'email'])
            ->keyBy('employee_number');

        return $entries->map(function (DpcSeniorityEntry $entry) use ($accounts): SittingRecipient {
            $account = $accounts->get($entry->employee_number);

            return new SittingRecipient(
                name: $entry->name,
                userId: $account?->id,
                mobile: $account?->mobile,
                email: $account?->email,
            );
        });
    }

    /**
     * SMS and e-mail, best effort, one recipient at a time.
     *
     * One unreachable mobile must not stop the next person being told — the same
     * rule `SendWorkflowNotification` follows, and the reason both loop rather than
     * sending in bulk.
     *
     * @param  Collection<int, SittingRecipient>  $recipients
     */
    private function deliver(Collection $recipients, string $message, string $subject): void
    {
        $sent = ['sms' => 0, 'email' => 0, 'unreachable' => 0];

        foreach ($recipients as $recipient) {
            if (! $recipient->isReachable()) {
                $sent['unreachable']++;

                continue;
            }

            try {
                if ($recipient->mobile !== null) {
                    $this->sms->send($recipient->mobile, $message);
                    $sent['sms']++;
                }

                if ($recipient->email !== null) {
                    $address = $recipient->email;
                    Mail::raw($message, function ($mail) use ($address, $subject): void {
                        $mail->to($address)->subject($subject);
                    });
                    $sent['email']++;
                }
            } catch (Throwable $e) {
                Log::warning('Sitting notification delivery failed for one recipient.', [
                    'recipient' => $recipient->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        /*
         | Logged rather than silent, because `unreachable` is the number that
         | matters: it is how many people the Commission believes it told and did
         | not. Today it is every candidate, and saying so in the log is the
         | difference between a known gap and a surprise.
         */
        Log::info('Sitting notification dispatched.', $sent + ['recipients' => $recipients->count()]);
    }

    /**
     * The in-app copy — the one channel that always works.
     *
     * Written for every recipient holding an account, which is every candidate and
     * every committee member who is a portal user. An external committee member
     * with no account gets the SMS and the e-mail and nothing here, which is
     * correct: there is no feed for them to read.
     *
     * @param  Collection<int, SittingRecipient>  $members
     * @param  Collection<int, SittingRecipient>  $candidates
     */
    private function recordInApp(
        DpcSittingScheduled $event,
        string $subject,
        string $body,
        Collection $members,
        Collection $candidates,
    ): void {
        $userIds = $members->concat($candidates)
            ->map(static fn (SittingRecipient $recipient): ?int => $recipient->userId)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($userIds === []) {
            return;
        }

        $this->centre->notify(null, [], $userIds, [
            'workflow' => 'dpc_cgpsc',
            'event' => $event->isReschedule ? 'sitting_rescheduled' : 'sitting_scheduled',
            'title' => $subject,
            'body' => $body,
            'subject_type' => $event->proposal->getMorphClass(),
            'subject_id' => $event->proposal->id,
        ]);
    }
}
