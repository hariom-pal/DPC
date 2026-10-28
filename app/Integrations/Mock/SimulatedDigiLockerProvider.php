<?php

declare(strict_types=1);

namespace App\Integrations\Mock;

use App\Integrations\Contracts\DigiLockerProvider;
use App\Integrations\DigiLocker\DigiLockerIdentity;
use App\Integrations\DigiLocker\DigiLockerUnavailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * A simulated DigiLocker for local and testing only (2026-10-27).
 *
 * The "DigiLocker" screen is a portal page where the tester types the identity a
 * DigiLocker account would hold; {@see issueCode()} stores it against a one-time
 * code that {@see fetchIdentity()} redeems. Refused in production by its binding
 * and by its screen. Every verification it produces is recorded with the method
 * `digilocker_simulated`, never as a real DigiLocker verification.
 */
final class SimulatedDigiLockerProvider implements DigiLockerProvider
{
    private const CACHE_PREFIX = 'digilocker-simulator:';

    public function name(): string
    {
        return 'digilocker_simulated';
    }

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return route('identity.simulator', ['state' => $state]);
    }

    /**
     * @param  array{name: string, date_of_birth: string, gender: string, aadhaar_verified: bool}  $identity
     */
    public function issueCode(array $identity): string
    {
        $code = Str::random(40);
        Cache::put(self::CACHE_PREFIX.$code, $identity, now()->addMinutes(5));

        return $code;
    }

    public function fetchIdentity(#[SensitiveParameter] string $code, #[SensitiveParameter] string $codeVerifier): DigiLockerIdentity
    {
        $identity = Cache::pull(self::CACHE_PREFIX.$code);

        if (! is_array($identity)) {
            throw new DigiLockerUnavailable('The simulated DigiLocker code is unknown or has expired.');
        }

        return new DigiLockerIdentity(
            (string) $identity['name'],
            Carbon::parse((string) $identity['date_of_birth'])->startOfDay(),
            (string) $identity['gender'],
            (bool) $identity['aadhaar_verified'],
            'SIMULATED-'.substr(hash('sha256', (string) $identity['name']), 0, 12),
        );
    }
}
