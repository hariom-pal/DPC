<?php

declare(strict_types=1);

namespace App\Integrations\Uidai\Otp;

use App\Integrations\Uidai\UidaiConfigurationException;
use App\Integrations\Uidai\UidaiProtocolException;
use DOMDocument;
use OpenSSLAsymmetricKey;
use SensitiveParameter;

/**
 * The encrypted PID block carrying the typed OTP — Authentication API 2.5 §4.1.
 * Ported from the CGMFPFED Welfare Platform (`OtpPidBlockBuilder`).
 *
 * On a biometric transaction a registered device builds and encrypts this. On
 * an OTP transaction there is no device, so this application must — which is
 * why it needs UIDAI's public ENCRYPTION certificate.
 *
 * **Not the certificate inside UIDAI's own responses.** That one is a SIGNING
 * key (Key Usage: Digital Signature, Non Repudiation — critical) and cannot be
 * used to encrypt. Obtain the encryption certificate for the environment (UAT
 * or production) from CSC/UIDAI.
 *
 * **The `ts` is load-bearing three times.** §4.1 takes the GCM nonce from its
 * last 12 bytes and the AAD from its last 16, and it prefixes the ciphertext. So
 * the SAME string must build the `Pid`, encrypt it, and go out in the request —
 * it is taken as an argument and never generated in here.
 */
final readonly class OtpPidBlockBuilder
{
    private const CIPHER = 'aes-256-gcm';

    private const NONCE_BYTES = 12;

    private const AAD_BYTES = 16;

    private const TAG_BYTES = 16;

    /** `Pid/@ver`. */
    private const PID_VERSION = '2.0';

    public function __construct(
        private ?string $certificatePath,
    ) {}

    /**
     * @param  string  $otp  the six digits the officer typed. Never stored, never logged.
     * @param  string  $timestamp  the SAME `ts` the Auth document carries
     *
     * @throws UidaiProtocolException the OTP is not six digits, or encryption failed
     * @throws UidaiConfigurationException the certificate is missing, unreadable or expired
     */
    public function build(#[SensitiveParameter] string $otp, string $timestamp): PidBlock
    {
        if (preg_match('/^\d{6}$/', $otp) !== 1) {
            throw new UidaiProtocolException('The Aadhaar OTP must be the six digits UIDAI sent.');
        }

        [$key, $identifier] = $this->publicKey();

        $pid = $this->pidXml($otp, $timestamp);

        // A fresh 256-bit key per transaction; §4.1 forbids reuse or storage.
        $sessionKey = random_bytes(32);

        $data = $this->encrypt($pid, $sessionKey, $timestamp);

        // §3.3.1, Hmac: SHA-256 of the Pid XML — the raw 32-byte digest — encrypted under the session key.
        $hmac = $this->encrypt(hash('sha256', $pid, true), $sessionKey, $timestamp);

        // §4.1: session key wrapped with UIDAI's public key, RSA/ECB/PKCS1Padding.
        if (! openssl_public_encrypt($sessionKey, $wrapped, $key, OPENSSL_PKCS1_PADDING)) {
            throw new UidaiProtocolException('The session key could not be encrypted with the UIDAI certificate.');
        }

        return new PidBlock(
            data: base64_encode($data),
            skey: base64_encode($wrapped),
            certificateIdentifier: $identifier,
            hmac: base64_encode($hmac),
        );
    }

    /**
     * `<Pid ts="" ver="2.0"><Pv otp=""/></Pid>` and nothing else: any other
     * element would have to be declared in `Uses`, and UIDAI cross-checks the two.
     */
    private function pidXml(#[SensitiveParameter] string $otp, string $timestamp): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;

        $pid = $document->createElement('Pid');
        $pid->setAttribute('ts', $timestamp);
        $pid->setAttribute('ver', self::PID_VERSION);

        $pv = $document->createElement('Pv');
        $pv->setAttribute('otp', $otp);
        $pid->appendChild($pv);

        $document->appendChild($pid);

        return (string) $document->saveXML();
    }

    /**
     * AES-256-GCM, nonce and AAD from `ts`, tag appended, `ts` PREFIXED.
     *
     * §4.1 says the ts is "appended", but the receiver must read `ts` before it
     * can derive the nonce, and UIDAI's sample and every reference implementation
     * put it first. Welfare documents the same discrepancy.
     */
    private function encrypt(#[SensitiveParameter] string $plaintext, #[SensitiveParameter] string $sessionKey, string $timestamp): string
    {
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $sessionKey,
            OPENSSL_RAW_DATA,
            substr($timestamp, -self::NONCE_BYTES),
            $tag,
            substr($timestamp, -self::AAD_BYTES),
            self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            throw new UidaiProtocolException('The PID block could not be encrypted.');
        }

        return $timestamp.$ciphertext.$tag;
    }

    /**
     * UIDAI's public encryption key, and the `ci` naming it — the certificate's
     * own expiry as YYYYMMDD (§3.3.1). Derived, not configured, so `ci` and the
     * key can never disagree (that disagreement is error 501).
     *
     * @return array{0: OpenSSLAsymmetricKey, 1: string}
     *
     * @throws UidaiConfigurationException
     */
    private function publicKey(): array
    {
        $path = $this->certificatePath;

        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            throw new UidaiConfigurationException('DPC_UIDAI_ENCRYPTION_CERTIFICATE does not name a readable file (UIDAI\'s public encryption certificate).');
        }

        $certificate = openssl_x509_read((string) file_get_contents($path));

        if ($certificate === false) {
            throw new UidaiConfigurationException('DPC_UIDAI_ENCRYPTION_CERTIFICATE is not a readable X.509 certificate (PEM expected).');
        }

        $parsed = openssl_x509_parse($certificate);
        $key = openssl_pkey_get_public($certificate);
        $expiry = is_array($parsed) ? ($parsed['validTo_time_t'] ?? null) : null;

        if ($key === false || ! is_int($expiry)) {
            throw new UidaiConfigurationException('The UIDAI encryption certificate could not be parsed.');
        }

        if ($expiry < time()) {
            throw new UidaiConfigurationException(sprintf(
                'The UIDAI encryption certificate expired on %s; UIDAI would answer error 501. Obtain the current one from CSC.',
                gmdate('Y-m-d', $expiry),
            ));
        }

        /*
         * The date AS WRITTEN in the certificate's notAfter, which X.509 states in
         * UTC — gmdate, so the app timezone cannot shift it. Welfare uses date()
         * under an app timezone that defaults to UTC, i.e. the same value. If
         * UIDAI answers 501 in UAT, the IST date is the first thing to try.
         */
        return [$key, gmdate('Ymd', $expiry)];
    }
}
