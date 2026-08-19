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
 * Only {@see self::IdentityFactors} is buildable from what exists. The other two
 * need data or a workflow the project does not have, and each returns a
 * structured *Pending Business Decision (BD-19)* rather than a broken screen or
 * an invented mechanism.
 */
enum SelfInspectionAuthDriver: string
{
    /** Facts from the department's own snapshot. Default. */
    case IdentityFactors = 'identity_factors';

    /** OTP to the officer — needs a contact channel no snapshot table carries. */
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
        return $this === self::IdentityFactors;
    }

    /**
     * Why this driver cannot run today — one sentence, in business language.
     */
    public function unavailableBecause(): string
    {
        return match ($this) {
            self::IdentityFactors => '',
            self::Otp => 'An OTP needs a mobile number or an email address for the officer. '
                .'None of the three officer-snapshot tables carries either, deliberately — they are '
                .'proposal-time snapshots with no link to a live employee record, which is what stops '
                .'a historical proposal changing when upstream data does.',
            self::Account => 'An Individual account has to be created and issued to the officer by '
                .'somebody. No screen in either deck does that, and inventing the workflow would decide '
                .'who owns an officer\'s credentials.',
        };
    }
}
