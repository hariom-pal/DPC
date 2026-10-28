<?php

declare(strict_types=1);

namespace App\Domain\SelfInspection\Services;

use App\Domain\Authentication\Services\OtpService;
use App\Domain\Dpc\Models\DpcEvaluationRow;
use App\Domain\SelfInspection\Enums\SelfInspectionAuthDriver;
use App\Domain\SelfInspection\Enums\SelfInspectionFactor;
use App\Domain\SelfInspection\Models\SelfInspectionAccess;
use App\Domain\Shared\ValueObjects\PendingBusinessDecision;
use App\Enums\Feature;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Who is asking, on S50 — *"LOGIN PAGE FOR INDIVIDUAL BEING CONSIDER FOR DPC"*.
 *
 * **The mechanism is configuration (BD-19).** S50 uses Aadhaar No. + Aadhaar
 * OTP; Q1 replaced Aadhaar project-wide with identifier + password and OTP
 * step-up, but that deviation assumed a **registered user**. An individual under
 * consideration is not one: they exist only as a row in an officer snapshot, and
 * none of the three snapshots carries a mobile or an email, so there is nothing
 * to send an OTP to. The three answers BD-19 chooses between are the three
 * drivers of {@see SelfInspectionAuthDriver}, so ruling it is a configuration
 * change. Two of the three refuse with a structured *Pending Business Decision*
 * rather than pretending.
 *
 * **What `identity_factors` actually asserts.** The officer proves they hold
 * facts their department recorded about them. That is weaker than a password and
 * it is stated plainly: the mitigations are that **all** configured factors must
 * match, the default set is three rather than S50's two, attempts are throttled,
 * every attempt is recorded whether it succeeds or fails, and a match returns
 * **only that officer's own rows** — never a list, never another officer, never a
 * mark or a remark.
 */
class SelfInspectionAuthenticator
{
    public function __construct(
        private readonly SelfInspectionAccessGuard $access,
        private readonly OtpService $otp,
    ) {}

    /**
     * The refusal that applies before any input is considered, or null to
     * proceed.
     *
     * Checked before the form is even shown, so an officer is never asked to
     * type three facts into a screen that was going to refuse them anyway.
     */
    public function unavailable(): ?PendingBusinessDecision
    {
        $driver = SelfInspectionAuthDriver::configured();

        if ($driver->isImplemented()) {
            return null;
        }

        return new PendingBusinessDecision(
            code: 'BD-19',
            title: 'Pending Business Decision (BD-19)',
            question: 'How does an individual officer log in to view their own data? S50 uses Aadhaar '
                .'No. and Aadhaar OTP, which decision Q1 replaced project-wide. '
                .$driver->unavailableBecause()
                .' Awaiting CGPSC.',
            notDone: [
                'No authentication mechanism was invented, and no officer data was shown.',
                sprintf(
                    'The `%s` driver is configured and is recognised, but cannot run on the data that exists.',
                    $driver->value,
                ),
                'Setting `dpc.self_inspection.authentication.driver` to `identity_factors` opens the '
                    .'screen on facts the department already records — no code change.',
            ],
        );
    }

    /**
     * Send a one-time code to the mobile number an officer claims (business
     * instruction, 2026-09-07: *"Self-Inspection is still not with mobile number and
     * OTP"*).
     *
     * ### It answers the same way whether the number is on a list or not
     *
     * Returning *"no such officer"* would turn this screen into a lookup service:
     * feed it numbers and it tells you which ones belong to somebody under
     * consideration for promotion. So a code is issued only when a row matches, and
     * the caller is told the same sentence either way. The officer whose number it
     * is gets a code; anybody else gets silence dressed as success.
     *
     * ### The number is normalised the way the rest of the portal normalises one
     *
     * Ten digits, whatever the officer typed around them. A department that recorded
     * `+91 94250-12345` and an officer who types `9425012345` are the same person,
     * and a match that depended on punctuation would fail for the officer rather
     * than for the attacker.
     */
    /**
     * Whether a code can actually reach the officer (2026-10-27): the SMS gateway
     * is enabled, or a fixed development code is in force (local/testing only).
     * When neither holds the screen says so, instead of announcing a code that was
     * never sent.
     */
    public function codesCanBeDelivered(): bool
    {
        return Feature::SmsGateway->enabled() || $this->otp->fixedCodeInForce();
    }

    public function requestCode(string $mobile, Request $request): void
    {
        $normalised = $this->normaliseMobile($mobile);

        if ($normalised === null || $this->rowsForMobile($normalised)->isEmpty()) {
            /*
             | Recorded as a failed attempt, and nothing sent. The record is the
             | point: somebody walking a range of numbers past this screen leaves a
             | trail, even though the screen tells them nothing.
             */
            $this->record(
                claimedName: '',
                row: null,
                matched: false,
                action: 'identify',
                request: $request,
            );

            return;
        }

        $this->otp->issue($normalised, OtpChannel::Sms, OtpPurpose::Login);
    }

    /**
     * Verify the code, and return the rows that mobile number belongs to.
     *
     * **Both halves must hold.** The code proves the officer holds the number; the
     * number is what selects the rows. A verified code for a number that has since
     * left every list under consideration returns nothing, because
     * {@see SelfInspectionAccessGuard::inspectableRows()} is re-read here rather
     * than remembered from when the code was sent.
     *
     * @return Collection<int, DpcEvaluationRow>
     */
    public function identifyByOtp(string $mobile, string $code, Request $request): Collection
    {
        $normalised = $this->normaliseMobile($mobile);

        /** @var Collection<int, DpcEvaluationRow> $none */
        $none = DpcEvaluationRow::query()->whereRaw('1 = 0')->get();

        if ($normalised === null || ! $this->otp->verify($normalised, OtpPurpose::Login, $code)) {
            $this->record(claimedName: '', row: null, matched: false, action: 'identify', request: $request);

            return $none;
        }

        $matches = $this->rowsForMobile($normalised);

        $this->record(
            claimedName: SelfInspectionFactor::normalise((string) $matches->first()?->name),
            row: $matches->first(),
            matched: $matches->isNotEmpty(),
            action: 'identify',
            request: $request,
        );

        return $matches;
    }

    /**
     * The inspectable rows carrying this mobile number.
     *
     * Compared on the **last ten digits of each**, not on the stored string: a
     * department may have typed a country code into the snapshot, and an officer
     * will not. Mirrors `CommitteeMemberDirectory::normaliseMobile()`, so one number
     * is one number everywhere in the portal.
     *
     * @return Collection<int, DpcEvaluationRow>
     */
    private function rowsForMobile(string $mobile): Collection
    {
        return $this->access->inspectableRows()
            ->filter(fn (DpcEvaluationRow $row): bool => $this->normaliseMobile((string) $row->mobile) === $mobile)
            ->values();
    }

    /**
     * The ten digits that identify an Indian mobile number, or null.
     */
    private function normaliseMobile(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) < 10 ? null : substr($digits, -10);
    }

    /**
     * Match the officer's answers against the chart rows they may inspect.
     *
     * @param  array<string, string>  $answers  factor value => the officer's answer
     * @return Collection<int, DpcEvaluationRow> the officer's own rows; empty on no match
     */
    public function identify(array $answers, Request $request): Collection
    {
        $factors = SelfInspectionFactor::configured();

        $claimedName = isset($answers[SelfInspectionFactor::Name->value])
            ? SelfInspectionFactor::normalise($answers[SelfInspectionFactor::Name->value])
            : '';

        /** @var Collection<int, DpcEvaluationRow> $matches */
        $matches = $this->access->inspectableRows()
            ->filter(function (DpcEvaluationRow $row) use ($factors, $answers): bool {
                foreach ($factors as $factor) {
                    $answer = $answers[$factor->value] ?? null;

                    if ($answer === null || $answer === '' || ! $factor->matches($row, $answer)) {
                        return false;
                    }
                }

                // An empty factor set would pass every row. `configured()`
                // guarantees at least one, and this asserts the guarantee here
                // rather than trusting it from a distance.
                return $factors !== [];
            })
            ->values();

        $this->record(
            claimedName: $claimedName,
            row: $matches->first(),
            matched: $matches->isNotEmpty(),
            action: 'identify',
            request: $request,
        );

        return $matches;
    }

    /**
     * Record a view or a print against an already-identified row.
     */
    public function recordView(DpcEvaluationRow $row, string $action, Request $request): void
    {
        $this->record(
            claimedName: SelfInspectionFactor::normalise($row->name),
            row: $row,
            matched: true,
            action: $action,
            request: $request,
        );
    }

    private function record(
        string $claimedName,
        ?DpcEvaluationRow $row,
        bool $matched,
        string $action,
        Request $request,
    ): void {
        SelfInspectionAccess::query()->create([
            // Truncated to the column width rather than allowed to throw: an
            // over-long claimed name is an attempt worth recording, and losing
            // the record because the attempt was malformed defeats the point.
            'claimed_name' => mb_substr($claimedName, 0, 255),
            'matched' => $matched,
            'dpc_evaluation_row_id' => $row?->id,
            'dpc_proposal_id' => $row?->dpc_proposal_id,
            'auth_driver' => SelfInspectionAuthDriver::configured()->value,
            'factors_required' => array_map(
                static fn (SelfInspectionFactor $factor): string => $factor->value,
                SelfInspectionFactor::configured(),
            ),
            'action' => $action,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
