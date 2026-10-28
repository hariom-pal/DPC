<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

use App\Integrations\DigiLocker\DigiLockerIdentity;
use App\Integrations\DigiLocker\DigiLockerUnavailable;
use SensitiveParameter;

/**
 * DigiLocker identity for officer verification (Phases 2–3, 2026-10-27).
 *
 * An OAuth 2.0 authorisation-code flow with PKCE: the officer is sent to
 * {@see authorizationUrl()}, signs in to DigiLocker and consents, and DigiLocker
 * returns a code to the portal's callback, which {@see fetchIdentity()} exchanges
 * for the identity attributes the portal compares — name, date of birth, gender
 * and whether the account is Aadhaar-verified. Nothing else is requested: no
 * Aadhaar number, document or photograph.
 */
interface DigiLockerProvider
{
    /**
     * `digilocker` or `digilocker_simulated` — recorded on every verification.
     */
    public function name(): string;

    public function authorizationUrl(string $state, string $codeChallenge): string;

    /**
     * @throws DigiLockerUnavailable on any technical failure (never counted as a mismatch)
     */
    public function fetchIdentity(#[SensitiveParameter] string $code, #[SensitiveParameter] string $codeVerifier): DigiLockerIdentity;
}
