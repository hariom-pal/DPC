<?php

declare(strict_types=1);

namespace App\Integrations\Uidai\Csc;

use App\Integrations\Uidai\UidaiAadhaarOtpProvider;
use App\Integrations\Uidai\UidaiConfigurationException;
use Illuminate\Support\Carbon;
use SensitiveParameter;

/**
 * The CSC wrapper envelope — CSC Auth Service APIs v1.1. Ported from the
 * CGMFPFED Welfare Platform (`CscEnvelopeCodec`), keeping only the AUA path.
 *
 * ```json
 * { "client_id": "...", "req_hash": "...", "req_data": "...", "client_ts": "..." }
 * ```
 *
 * **The construction is verified, not inferred.** Welfare recovered it by
 * reproducing the `req_hash` of CSC's own worked sample byte for byte:
 *
 *     req_data = base64( UIDAI XML )                       -- NOT encrypted
 *     req_hash = base64( AES-CBC( lowercase_hex( sha256( req_data ) ),
 *                                 key = authToken as raw ASCII (32 → AES-256, 16 → AES-128),
 *                                 iv  = "0000000000000000" ) )
 *
 * The test suite pins it against that same published sample.
 *
 * **The fixed all-zero IV** is mandated by CSC and applied to a digest of a
 * payload that already contains a unique `txn` and a one-shot session key, so
 * no two inputs repeat. Acceptable in this position only — do not copy it.
 */
final readonly class CscEnvelopeCodec
{
    /** CSC Auth Service APIs v1.1: 16-byte IV, AES/CBC/PKCS5Padding. */
    public const IV = '0000000000000000';

    public function __construct(
        #[SensitiveParameter] private string $authToken,
    ) {}

    /** `req_data`: the UIDAI XML, base64-encoded and nothing more. */
    public function encodeData(string $uidaiXml): string
    {
        return base64_encode($uidaiXml);
    }

    /** `req_hash`, and `res_hash` on the way back — the same construction in both directions. */
    public function hash(string $base64Payload): string
    {
        $ciphertext = openssl_encrypt(hash('sha256', $base64Payload), $this->cipher(), $this->authToken, OPENSSL_RAW_DATA, self::IV);

        if ($ciphertext === false) {
            throw new UidaiConfigurationException('The CSC request hash could not be computed with the configured CSC_AUTH_TOKEN.');
        }

        return base64_encode($ciphertext);
    }

    /**
     * Whether a returned hash matches the returned payload. Constant-time.
     *
     * DECRYPT AND COMPARE THE DIGEST, not encrypt-and-compare: CSC's legacy
     * helper pads twice (by hand, then OpenSSL), so the same digest can arrive
     * as two different ciphertexts. Decrypting with padding left in place and
     * finding the 64-hex digest reads either. Falls back to encrypt-and-compare
     * when the hash will not decrypt at all.
     */
    public function verify(string $base64Payload, ?string $expectedHash): bool
    {
        if ($expectedHash === null || $expectedHash === '') {
            return false;
        }

        $digest = $this->decryptHash($expectedHash);

        if ($digest !== null) {
            return hash_equals(hash('sha256', $base64Payload), $digest);
        }

        return hash_equals($this->hash($base64Payload), $expectedHash);
    }

    /** The SHA-256 hex digest inside a CSC hash, or null if it does not contain one. */
    public function decryptHash(string $hash): ?string
    {
        $ciphertext = base64_decode(preg_replace('/\s+/', '', $hash) ?? '', true);

        if ($ciphertext === false || $ciphertext === '' || strlen($ciphertext) % 16 !== 0) {
            return null;
        }

        $plaintext = openssl_decrypt($ciphertext, $this->cipher(), $this->authToken, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, self::IV);

        if ($plaintext === false || preg_match('/[0-9a-f]{64}/i', $plaintext, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[0]);
    }

    /**
     * `client_ts` — IST WITH the "+05:30" offset.
     *
     * Taken from CSC's working Java reference (`Utility.getTs()` appends the
     * literal "+05:30"). Converted to IST first so the asserted offset is true
     * wherever this server runs. NOT the UIDAI `ts` inside the XML, which
     * carries no offset — see {@see UidaiAadhaarOtpProvider}.
     */
    public function timestamp(): string
    {
        return Carbon::now('Asia/Kolkata')->format('Y-m-d\TH:i:s').'+05:30';
    }

    /** A 32-character token is an AES-256 key; a 16-character one is AES-128. */
    private function cipher(): string
    {
        return match (strlen($this->authToken)) {
            32 => 'AES-256-CBC',
            16 => 'AES-128-CBC',
            default => throw new UidaiConfigurationException(
                'CSC_AUTH_TOKEN must be 16 or 32 characters: it is used directly as the AES key for the request hash.'
            ),
        };
    }
}
