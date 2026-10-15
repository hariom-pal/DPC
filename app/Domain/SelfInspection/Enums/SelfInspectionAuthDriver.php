<?php

declare(strict_types=1);

namespace App\Domain\SelfInspection\Enums;

/**
 * How an individual officer proves who they are (S50).
 *
 * **The three cases are the three answers BD-19 chooses between**, so ruling it
 * is a configuration change rather than a code change:
 * `dpc.self_inspection.authentication.driver`.
 *
 * **{@see self::Otp} became buildable on 2026-09-07**, when the officer snapshots
 * gained a mobile number. It had been blocked on exactly one thing — *"none of the
 * three officer-snapshot tables carries a contact channel"* — and that is no longer
 * true. It is now the default: an officer proves they hold the number their
 * department recorded for them, which is a stronger claim than reciting three facts
 * that circulate on a seniority list.
 *
 * {@see self::Account} still needs a workflow neither deck has, and returns a
 * structured *Pending Business Decision (BD-19)* rather than an invented mechanism.
 */
enum SelfInspectionAuthDriver: string
{
    /** Facts from the department's own snapshot. */
    case IdentityFactors = 'identity_factors';

    /** A one-time code to the mobile number the department recorded. Default. */
    case Otp = 'otp';

    /** A provisioned Individual account — a workflow in neither deck. */
    case Account = 'account';

    public static function configured(): self
    {
        return self::tryFrom((string) config('dpc.self_inspection.authentication.driver', 'identity_factors'))
            ?? self::IdentityFactors;
    }

    public function isImplemented(): bool
    {
        return $this === self::IdentityFactors || $this === self::Otp;
    }

    /**
     * Whether this driver proves possession of a contact channel rather than
     * knowledge of facts about the officer.
     */
    public function usesOtp(): bool
    {
        return $this === self::Otp;
    }

    /**
     * Why this driver cannot run today — one sentence, in business language.
     */
    public function unavailableBecause(): string
    {
        return match ($this) {
            self::IdentityFactors, self::Otp => '',
            self::Account => 'An Individual account has to be created and issued to the officer by '
                .'somebody. No screen in either deck does that, and inventing the workflow would decide '
                .'who owns an officer\'s credentials.',
        };
    }
}
