<?php

declare(strict_types=1);

namespace App\Integrations\Uidai\Otp;

use App\Integrations\Uidai\Support\SafeXml;
use App\Integrations\Uidai\UidaiProtocolException;

/**
 * Reads `<OtpRes>` — OTP Request API 2.5 §2.5. Ported from the CGMFPFED
 * Welfare Platform (`OtpResponseParser`).
 *
 * `sent` is POSITIVE: only `ret="y"` (case-insensitive, as CSC's reference
 * reads it) with no `err` counts. A missing or malformed `ret` is "not sent".
 *
 * A `txn` that differs from the one we sent is refused: that identifier is what
 * will link the typed code to this request, so accepting someone else's answer
 * could let one officer's code verify another's Aadhaar.
 */
final class OtpResponseParser
{
    /** @throws UidaiProtocolException */
    public function parse(string $xml, string $expectedTxn): OtpResponse
    {
        $root = SafeXml::root($xml, 'OtpRes');

        $txn = SafeXml::attribute($root, 'txn');

        if ($txn !== null && $txn !== $expectedTxn) {
            throw new UidaiProtocolException(sprintf(
                'The OTP response is for transaction [%s] but [%s] was sent.',
                $txn,
                $expectedTxn,
            ));
        }

        $error = SafeXml::attribute($root, 'err');

        return new OtpResponse(
            sent: strtolower((string) SafeXml::attribute($root, 'ret')) === 'y' && $error === null,
            txn: $txn,
            code: SafeXml::attribute($root, 'code'),
            errorCode: $error,
        );
    }
}
