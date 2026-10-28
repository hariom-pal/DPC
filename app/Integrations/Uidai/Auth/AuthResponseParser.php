<?php

declare(strict_types=1);

namespace App\Integrations\Uidai\Auth;

use App\Integrations\Uidai\Support\SafeXml;
use App\Integrations\Uidai\UidaiProtocolException;

/**
 * Reads `<AuthRes>` — Authentication API 2.5 §3.4. Ported from the CGMFPFED
 * Welfare Platform (`AuthResponseParser`).
 *
 * **Only an explicit yes authenticates.** `ret` is compared case-insensitively
 * to "y" (CSC's Java reference uses `equalsIgnoreCase`); anything else —
 * "n", missing, garbage — is not authenticated.
 *
 * **A different `txn` is refused**, which is STRICTER than Welfare (it only logs,
 * following the Java reference). DPC's answer is a bare yes/no with nothing
 * else to cross-check, so an answer for another transaction must not count as
 * this officer's yes. If CSC proves to rewrite `txn` in UAT, this is the line.
 */
final class AuthResponseParser
{
    /** @throws UidaiProtocolException */
    public function parse(string $xml, string $expectedTxn): AuthResponse
    {
        $root = SafeXml::root($xml, 'AuthRes');

        $txn = SafeXml::attribute($root, 'txn');

        if ($txn !== null && $txn !== $expectedTxn) {
            throw new UidaiProtocolException(sprintf(
                'The authentication response is for transaction [%s] but [%s] was sent.',
                $txn,
                $expectedTxn,
            ));
        }

        return new AuthResponse(
            authenticated: strtolower((string) SafeXml::attribute($root, 'ret')) === 'y',
            txn: $txn,
            code: SafeXml::attribute($root, 'code'),
            errorCode: SafeXml::attribute($root, 'err'),
            actionCode: SafeXml::attribute($root, 'actn'),
        );
    }
}
