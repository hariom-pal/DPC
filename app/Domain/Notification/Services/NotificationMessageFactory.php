<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

/**
 * A workflow transition, said in a sentence a person would use.
 *
 * The SMS channel sends *"ODPC: dpc_cgpsc was 'allot' (status: allotted)."* That
 * is adequate for a 160-character SMS to somebody who already knows what they are
 * waiting for. It is not adequate for a screen an Administrative Head opens
 * expecting to be told what needs them.
 *
 * **Written as a lookup with a general fallback, deliberately.** A `match` that
 * threw on an unknown event would mean a new milestone's transition could not be
 * notified until this file was edited — so an unrecognised event still produces a
 * legible sentence from the workflow, the event and the state. Adding a phrasing
 * here is an improvement, never a prerequisite.
 */
class NotificationMessageFactory
{
    /**
     * Human titles per workflow and event.
     *
     * Keyed `workflow.event`, because `approve` means different things in
     * `pre_dpc` and `dpc_proceeding` and collapsing them would be wrong on both.
     *
     * @var array<string, string>
     */
    private const TITLES = [
        // Pre-DPC (S7–S11)
        'pre_dpc.submit' => 'A Pre-DPC proposal is awaiting your approval',
        'pre_dpc.approve' => 'Your Pre-DPC proposal has been approved',
        'pre_dpc.return' => 'Your Pre-DPC proposal has been returned for correction',
        'pre_dpc.cancel' => 'A Pre-DPC proposal was cancelled',

        // DPC proposal (S12–S25)
        'dpc_proposal.submit' => 'A DPC proposal is awaiting your approval',
        'dpc_proposal.approve' => 'Your DPC proposal has been approved and forwarded to the Commission',
        'dpc_proposal.return' => 'Your DPC proposal has been returned for correction',
        'dpc_proposal.cancel' => 'A DPC proposal was cancelled',

        // CGPSC workflow (S26–S30)
        'dpc_cgpsc.allot' => 'Your DPC has been allotted to a Member of the Commission',
        'dpc_cgpsc.reallot' => 'Your DPC has been re-allotted to a different Member',
        'dpc_cgpsc.accept' => 'The Member has accepted your DPC',
        'dpc_cgpsc.schedule' => 'A date has been fixed for your DPC',
        'dpc_cgpsc.reschedule' => 'The date for your DPC has been changed',

        // Proceeding (S31–S34)
        'dpc_proceeding.open' => 'The sitting for your DPC has opened',
        'dpc_proceeding.approve_result' => 'The result of your DPC has been approved by the Commission',
        'dpc_proceeding.cancel_dpc' => 'Your DPC has been cancelled by the Commission',

        // Circulation (S45–S48). All seven transitions, because the demo seed
        // exercises `consent` and `sign` and both fell through to the fallback —
        // which is how the fallback earns its place *and* how the gaps get found.
        'dpc_circulation.submit' => 'A circulation note is awaiting your approval',
        'dpc_circulation.approve' => 'A circulation note has been approved and sent to the Commission',
        'dpc_circulation.return' => 'A circulation note has been returned for correction',
        'dpc_circulation.cancel' => 'A circulation note was cancelled',
        'dpc_circulation.consent' => 'The Commission has recorded its decision on a circulation note',
        'dpc_circulation.return_to_department' => 'A circulation note has been returned for further evidence',
        // The operative act (AQ-17): the held promotions are released here, not at
        // approval, so this is the one an officer is actually waiting for.
        'dpc_circulation.sign' => 'A circulation note has been signed — held promotions are now resolved',
    ];

    public function title(string $workflow, string $event, string $toState): string
    {
        return self::TITLES["{$workflow}.{$event}"]
            ?? sprintf(
                '%s — %s (now %s)',
                $this->humanise($workflow),
                $this->humanise($event),
                $this->humanise($toState),
            );
    }

    /**
     * The second line: the mechanical detail, kept because the title is a
     * paraphrase and somebody will need to know exactly which transition fired.
     */
    public function body(string $workflow, string $event, string $toState, ?string $remarks): string
    {
        $body = sprintf('%s · %s → %s', $workflow, $event, $toState);

        if ($remarks !== null && trim($remarks) !== '') {
            // Remarks are the whole point of a `return`: they say what to fix.
            $body .= ' · Remarks: '.trim($remarks);
        }

        return $body;
    }

    private function humanise(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }
}
