<?php

declare(strict_types=1);

namespace App\Integrations\Uidai;

use SensitiveParameter;

/**
 * Everything the live Aadhaar OTP provider reads from `config('dpc.aadhaar_otp.uidai')`.
 *
 * One object rather than a dozen `config()` calls scattered through the
 * builders, so a test constructs the whole integration from literals and the
 * production path from configuration through the same constructor.
 *
 * **Nothing is validated at construction.** The binding in AppServiceProvider
 * resolves this provider whenever anything type-hints the contract — including
 * screens that never send an OTP — so a missing secret must not turn every one
 * of them into a 500. {@see self::problems()} is asked at the moment a request
 * is about to be sent instead.
 *
 * **'' is unset.** `env('K', default)` returns '' for a key present but blank in
 * `.env`, which would otherwise defeat every default below; {@see self::string()}
 * and {@see self::integer()} put the default back.
 */
final readonly class UidaiSettings
{
    /** OTP Request API 2.5 / Authentication API 2.5, `ver`. */
    public const DEFAULT_API_VERSION = '2.5';

    public function __construct(
        /** CSC authentication API host, e.g. https://cscauths.csccloud.in (UAT). */
        public string $baseUrl,
        /** Path on that host, e.g. aua/springboot/auth/req. */
        public string $endpoint,
        /** CSC-issued client id, sent as `client_id` in every envelope. */
        public string $clientId,
        /** CSC `authToken` header — and the AES key of `req_hash`. Never logged. */
        #[SensitiveParameter] public string $authToken,
        /** UIDAI `ac`. Empty is sent as empty: CSC as the ASA may supply its own. */
        public string $auaCode,
        /** UIDAI `sa`. */
        public string $subAuaCode,
        /** UIDAI `lk`. */
        #[SensitiveParameter] public string $licenceKey,
        /** Path to UIDAI's public ENCRYPTION certificate (not the signing one). */
        public ?string $encryptionCertificatePath,
        /** Prefix of every UIDAI `txn`. Must not start with "U" (error 587). */
        public string $txnPrefix = 'DPC',
        public string $apiVersion = self::DEFAULT_API_VERSION,
        public int $timeoutSeconds = 30,
        /**
         * Re-sends of a request whose RESPONSE WAS NEVER SEEN (connect failure,
         * timeout, 5xx). 0 by default: a re-sent OTP request may text the officer
         * twice, and a re-sent authentication is refused by UIDAI as error 563.
         */
        public int $retryAttempts = 0,
        /** Refuse a response whose `res_hash` does not match `res_data`. */
        public bool $verifyResponseHash = true,
    ) {}

    public static function fromConfig(): self
    {
        $path = self::string('encryption_certificate', '');

        return new self(
            baseUrl: rtrim(self::string('base_url', 'https://cscauths.csccloud.in'), '/'),
            endpoint: ltrim(self::string('endpoint', 'aua/springboot/auth/req'), '/'),
            clientId: self::string('client_id', ''),
            authToken: self::string('auth_token', ''),
            auaCode: self::string('aua_code', ''),
            subAuaCode: self::string('sub_aua_code', ''),
            licenceKey: self::string('licence_key', ''),
            encryptionCertificatePath: $path === '' ? null : $path,
            txnPrefix: self::string('txn_prefix', 'DPC'),
            apiVersion: self::string('api_version', self::DEFAULT_API_VERSION),
            timeoutSeconds: max(1, self::integer('timeout_seconds', 30)),
            retryAttempts: max(0, self::integer('retry_attempts', 0)),
            verifyResponseHash: (bool) config('dpc.aadhaar_otp.uidai.verify_response_hash', true),
        );
    }

    /**
     * What stops a request being sent at all. Names settings, never values.
     *
     * @param  bool  $forVerification  the OTP-auth leg also needs the UIDAI encryption certificate
     * @return list<string>
     */
    public function problems(bool $forVerification): array
    {
        $problems = [];

        if ($this->baseUrl === '' || $this->endpoint === '') {
            $problems[] = 'CSC_AUTH_BASE_URL / CSC_AUTH_ENDPOINT is not set.';
        }

        if ($this->clientId === '') {
            $problems[] = 'CSC_AUTH_CLIENT_ID is not set.';
        }

        if (! in_array(strlen($this->authToken), [16, 32], true)) {
            $problems[] = 'CSC_AUTH_TOKEN is not set, or is not 16 or 32 characters (it is used directly as the AES key).';
        }

        if ($this->txnPrefix === '' || str_starts_with(strtoupper($this->txnPrefix), 'U')
            || preg_match('/^[A-Za-z0-9]+$/', $this->txnPrefix) !== 1 || strlen($this->txnPrefix) > 10) {
            $problems[] = 'DPC_UIDAI_TXN_PREFIX must be 1-10 letters/digits and must not start with "U" (UIDAI reserves it; error 587).';
        }

        if ($forVerification) {
            $path = $this->encryptionCertificatePath;

            if ($path === null || ! is_file($path) || ! is_readable($path)) {
                $problems[] = 'DPC_UIDAI_ENCRYPTION_CERTIFICATE does not name a readable file (UIDAI\'s public encryption certificate, required to encrypt the OTP).';
            }
        }

        return $problems;
    }

    /**
     * Keeps the token and licence key out of `dd()`, `var_dump()` and traces.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'endpoint' => $this->endpoint,
            'clientId' => $this->clientId,
            'authToken' => '[redacted]',
            'auaCode' => $this->auaCode,
            'subAuaCode' => $this->subAuaCode,
            'licenceKey' => '[redacted]',
            'encryptionCertificatePath' => $this->encryptionCertificatePath,
            'txnPrefix' => $this->txnPrefix,
            'apiVersion' => $this->apiVersion,
        ];
    }

    private static function string(string $key, string $default): string
    {
        $value = config('dpc.aadhaar_otp.uidai.'.$key);

        if ($value === null || ! is_scalar($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value === '' ? $default : $value;
    }

    private static function integer(string $key, int $default): int
    {
        $value = self::string($key, '');

        return is_numeric($value) ? (int) $value : $default;
    }
}
