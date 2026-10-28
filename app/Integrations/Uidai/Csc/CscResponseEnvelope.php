<?php

declare(strict_types=1);

namespace App\Integrations\Uidai\Csc;

/**
 * The CSC wrapper's reply — CSC Auth Service APIs v1.1. Ported from the
 * CGMFPFED Welfare Platform, where it is proven against the live UAT service.
 *
 * ```json
 * { "res_code": "10", "client_id": "...", "res_msg": "...", "res_hash": "...", "res_data": "...", "status": null }
 * ```
 *
 * **`res_code = 10` does not mean authenticated.** CSC's own worked sample is
 * `res_code: "10"` carrying `<OtpRes ret="n" err="952"/>`. The code says "UIDAI
 * answered and `res_data` is available"; what UIDAI answered is inside
 * `res_data` and nowhere else. Treating 10 as success is the single most likely
 * way to build a system that verifies everybody.
 */
final readonly class CscResponseEnvelope
{
    /** The only code that means "a UIDAI answer is enclosed". */
    public const PROCESSED = '10';

    public function __construct(
        public string $responseCode,
        public ?string $responseMessage,
        public ?string $clientId,
        /** Base64 UIDAI XML. Absent whenever responseCode is not "10". */
        public ?string $data,
        public ?string $hash,
        public ?string $status,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $code = $payload['res_code'] ?? '';

        return new self(
            responseCode: is_scalar($code) ? (string) $code : '',
            responseMessage: self::nullableString($payload['res_msg'] ?? null),
            clientId: self::nullableString($payload['client_id'] ?? null),
            data: self::nullableString($payload['res_data'] ?? null),
            hash: self::nullableString($payload['res_hash'] ?? null),
            status: self::nullableString($payload['status'] ?? null),
        );
    }

    /** Whether UIDAI answered at all — not whether it said yes. */
    public function wasProcessed(): bool
    {
        return $this->responseCode === self::PROCESSED && $this->data !== null && $this->data !== '';
    }

    /** `res_data` is plain base64 of the UIDAI XML on the AUA path — not encrypted. */
    public function decodedData(): ?string
    {
        if ($this->data === null || $this->data === '') {
            return null;
        }

        $decoded = base64_decode($this->data, true);

        return $decoded === false ? null : $decoded;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
