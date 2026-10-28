<?php

declare(strict_types=1);

namespace App\Integrations\Uidai\Otp;

use App\Integrations\Uidai\UidaiProtocolException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * UIDAI `txn` — OTP API / Authentication API 2.5 §3.3.1.
 *
 * The SAME identifier travels in the `<Otp>` request and in the `<Auth>` that
 * carries the typed code: UIDAI ties the OTP to it, and answers 402 ("OTP
 * belongs to a different transaction") when they differ.
 *
 * Three rules, each a remote rejection caught locally: at most 50 characters, a
 * fixed character set, and never starting with "U" — UIDAI reserves that
 * namespace ("UKC:" e-KYC, "ubfd:" Best Finger Detection) and answers 587.
 */
final class TransactionId
{
    private const ALLOWED = '/^[A-Za-z0-9.,\-\/\\\\():]+$/';

    /** prefix + yymmddHHMMSS + 8 random alphanumerics, e.g. DPC261022143005Ab3dE9xQ. */
    public static function generate(string $prefix): string
    {
        $txn = $prefix.Carbon::now('Asia/Kolkata')->format('ymdHis').Str::random(8);

        self::assertAcceptable($txn);

        return $txn;
    }

    /** @throws UidaiProtocolException */
    public static function assertAcceptable(string $txn): void
    {
        if ($txn === '' || strlen($txn) > 50) {
            throw new UidaiProtocolException('The UIDAI transaction identifier must be 1 to 50 characters (API 2.5 §3.3.1).');
        }

        if (preg_match(self::ALLOWED, $txn) !== 1) {
            throw new UidaiProtocolException('The UIDAI transaction identifier contains characters UIDAI does not accept.');
        }

        if (str_starts_with(strtoupper($txn), 'U')) {
            throw new UidaiProtocolException('The UIDAI transaction identifier must not start with "U": UIDAI reserves that namespace (error 587).');
        }
    }
}
