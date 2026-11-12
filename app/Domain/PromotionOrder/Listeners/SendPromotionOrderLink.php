<?php

declare(strict_types=1);

namespace App\Domain\PromotionOrder\Listeners;

use App\Domain\Authentication\Models\User;
use App\Domain\Cgpsc\ValueObjects\SittingRecipient;
use App\Domain\Dpc\Models\DpcCommitteeMember;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\Notification\Services\NotificationCentre;
use App\Domain\Notification\Templates\MessageTemplates;
use App\Domain\PromotionOrder\Events\PromotionOrderIssued;
use App\Integrations\Contracts\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Coder Instructions rule 4.3: the order's link goes to the officers it promotes and
 * to every serving member of the DPC committee — by SMS (when the gateway is on), by
 * e-mail, and in-app for those with an account. The link is signed, so a member with
 * no portal account can open the order too.
 *
 * Delivery never undoes the order: a recipient that fails is logged and the rest
 * still receive it.
 */
class SendPromotionOrderLink implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly SmsSender $sms,
        private readonly NotificationCentre $centre,
        private readonly MessageTemplates $templates,
    ) {}

    public function handle(PromotionOrderIssued $event): void
    {
        $order = $event->order;

        try {
            $proposal = $order->proposal;
            $link = URL::signedRoute('promotion-orders.public', ['order' => $order->uuid]);
            $subject = (string) __('notifications.promotion_order.subject', ['number' => $order->order_number]);
            $message = (string) __('notifications.promotion_order.message', [
                'number' => $order->order_number,
                'date' => $order->order_date->format('d-m-Y'),
                'post' => $proposal->promotionToPost?->name,
                'link' => $link,
            ]);

            $recipients = $this->officers($order->dpc_proposal_id, (array) $order->officers)
                ->concat($this->committee($order->dpc_proposal_id));

            // The Administrator's wording (2026-11-12); the in-app notice keeps the built-in one.
            $values = ['number' => $order->order_number, 'date' => $order->order_date->format('d-m-Y'), 'post' => $proposal->promotionToPost?->name, 'link' => $link];
            $this->deliver($recipients, $this->templates->sms('promotion_order.issued', $values), $this->templates->email('promotion_order.issued', $values));

            $userIds = $recipients->map(static fn (SittingRecipient $recipient): ?int => $recipient->userId)->filter()->unique()->values()->all();

            if ($userIds !== []) {
                $this->centre->notify(null, [], $userIds, [
                    'workflow' => 'dpc_promotion_order',
                    'event' => 'promotion_order_issued',
                    'title' => $subject,
                    'body' => $message,
                    'url' => $link,
                    'subject_type' => $proposal->getMorphClass(),
                    'subject_id' => $proposal->id,
                ]);
            }

            $order->forceFill(['notified_at' => now()])->save();
        } catch (Throwable $e) {
            Log::warning('Promotion order notification could not be sent.', ['order' => $order->uuid, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The promoted officers: their portal account by Employee ID, and the mobile the
     * department recorded for them where the account has none.
     *
     * @param  list<array<string, mixed>>  $officers
     * @return Collection<int, SittingRecipient>
     */
    private function officers(int $proposalId, array $officers): Collection
    {
        $numbers = array_values(array_filter(array_map(static fn (array $officer): ?string => $officer['employee_number'] ?? null, $officers)));
        $accounts = User::query()->whereIn('employee_number', $numbers)->get(['id', 'employee_number', 'mobile', 'email'])->keyBy('employee_number');
        // The mobile the department recorded, found by the officer's place on the list.
        $mobiles = DpcEvaluationRow::query()->where('dpc_proposal_id', $proposalId)
            ->whereIn('sn', array_map(static fn (array $officer): int => (int) $officer['sn'], $officers))
            ->pluck('mobile', 'sn');

        return (new Collection($officers))->map(function (array $officer) use ($accounts, $mobiles): SittingRecipient {
            $number = $officer['employee_number'] ?? null;
            $account = $number === null ? null : $accounts->get($number);
            $recorded = $mobiles->get((int) $officer['sn']);

            return new SittingRecipient(
                name: (string) $officer['name'],
                userId: $account?->id,
                mobile: $account->mobile ?? (is_string($recorded) && $recorded !== '' ? $recorded : null),
                email: $account?->email,
            );
        });
    }

    /**
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
     * @param  Collection<int, SittingRecipient>  $recipients
     * @param  array{body: string, dlt_template_id: string|null}|null  $sms  null: switched off
     * @param  array{subject: string, body: string}|null  $mailText  null: switched off
     */
    private function deliver(Collection $recipients, ?array $sms, ?array $mailText): void
    {
        $sent = ['sms' => 0, 'email' => 0, 'unreachable' => 0];

        foreach ($recipients as $recipient) {
            if (! $recipient->isReachable()) {
                $sent['unreachable']++;

                continue;
            }

            try {
                if ($sms !== null && $recipient->mobile !== null) {
                    $this->sms->send($recipient->mobile, $sms['body'], $sms['dlt_template_id']);
                    $sent['sms']++;
                }

                if ($mailText !== null && $recipient->email !== null) {
                    $address = $recipient->email;
                    Mail::raw($mailText['body'], function ($mail) use ($address, $mailText): void {
                        $mail->to($address)->subject($mailText['subject']);
                    });
                    $sent['email']++;
                }
            } catch (Throwable $e) {
                Log::warning('Promotion order notification failed for one recipient.', ['recipient' => $recipient->name, 'error' => $e->getMessage()]);
            }
        }

        Log::info('Promotion order notification dispatched.', $sent + ['recipients' => $recipients->count()]);
    }
}
