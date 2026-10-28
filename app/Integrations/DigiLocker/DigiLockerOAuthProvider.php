<?php

declare(strict_types=1);

namespace App\Integrations\DigiLocker;

use App\Integrations\Contracts\DigiLockerProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * The real DigiLocker provider — the integration boundary (2026-10-27).
 *
 * **Not verified against DigiLocker.** Written to the public Requester API
 * Specification v1.12 (authorisation-code flow with PKCE; the token response
 * carries `name`, `dob` as DDMMYYYY, `gender` M/F/T, `eaadhaar` Y/N and
 * `digilockerid`). Every endpoint and credential comes from configuration
 * (`dpc.digilocker`), issued at Requester onboarding. Until a sandbox exchange has
 * been run against it, it must be treated as unproven: see the audit report §11.
 */
final class DigiLockerOAuthProvider implements DigiLockerProvider
{
    public function __construct(
        private readonly string $authorizeUrl,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        #[SensitiveParameter] private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly int $timeoutSeconds = 15,
    ) {}

    public static function fromConfig(): self
    {
        $config = (array) config('dpc.digilocker');

        foreach (['authorize_url', 'token_url', 'client_id', 'client_secret', 'redirect_uri'] as $key) {
            if (! is_string($config[$key] ?? null) || $config[$key] === '') {
                throw new DigiLockerUnavailable("DigiLocker is enabled but dpc.digilocker.{$key} is not configured.");
            }
        }

        return new self(
            (string) $config['authorize_url'],
            (string) $config['token_url'],
            (string) $config['client_id'],
            (string) $config['client_secret'],
            (string) $config['redirect_uri'],
            (int) ($config['timeout_seconds'] ?? 15),
        );
    }

    public function name(): string
    {
        return 'digilocker';
    }

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return $this->authorizeUrl.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    public function fetchIdentity(#[SensitiveParameter] string $code, #[SensitiveParameter] string $codeVerifier): DigiLockerIdentity
    {
        try {
            $response = Http::asForm()->timeout($this->timeoutSeconds)->post($this->tokenUrl, [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $this->redirectUri,
                'code_verifier' => $codeVerifier,
            ]);
        } catch (Throwable $e) {
            throw new DigiLockerUnavailable('DigiLocker could not be reached.', 0, $e);
        }

        if (! $response->successful()) {
            throw new DigiLockerUnavailable('DigiLocker refused the token request (HTTP '.$response->status().').');
        }

        return self::parse((array) $response->json());
    }

    /**
     * The token response's identity attributes, as the specification documents them.
     *
     * @param  array<string, mixed>  $body
     */
    public static function parse(array $body): DigiLockerIdentity
    {
        $name = trim((string) ($body['name'] ?? ''));
        $dob = (string) ($body['dob'] ?? '');
        $gender = strtoupper(trim((string) ($body['gender'] ?? '')));

        if ($name === '' || ! preg_match('/^\d{8}$/', $dob) || ! in_array($gender, ['M', 'F', 'T'], true)) {
            throw new DigiLockerUnavailable('DigiLocker returned no usable name, date of birth or gender.');
        }

        $date = Carbon::createFromFormat('dmY', $dob);

        if ($date === null || $date->format('dmY') !== $dob) {
            throw new DigiLockerUnavailable('DigiLocker returned an invalid date of birth.');
        }

        return new DigiLockerIdentity(
            $name,
            $date->startOfDay(),
            $gender,
            strtoupper((string) ($body['eaadhaar'] ?? 'N')) === 'Y',
            is_string($body['digilockerid'] ?? null) ? $body['digilockerid'] : null,
        );
    }
}
