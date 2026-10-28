<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Uidai;

use App\Integrations\Uidai\Csc\CscEnvelopeCodec;
use App\Integrations\Uidai\Csc\CscResponseEnvelope;
use PHPUnit\Framework\TestCase;

/**
 * Pins the CSC envelope construction recovered from CSC Auth Service APIs v1.1:
 * the hash is taken over the base64, as a hex digest, encrypted with the token
 * as the raw ASCII AES key and an IV of sixteen ASCII zeros.
 *
 * The CGMFPFED Welfare Platform pins this against the worked sample in CSC's
 * document, whose authToken is a live UAT credential. That token is kept out of
 * this repository: the recipe is pinned here with a made-up token instead.
 */
class CscEnvelopeCodecTest extends TestCase
{
    /** A made-up 32-character token: never a real CSC credential. */
    private const SAMPLE_TOKEN = '0123456789abcdef0123456789abcdef';

    /** The document's `req_data`: base64 of an `<Otp>` request. */
    private const SAMPLE_REQ_DATA = 'PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz48T3RwIGFjPSJBVUFERlQiIGxrPSIiIHNhPSJBVUFERlQiIHRzPSIyMDI2LTA3LTIxVDEzOjMwOjU5KzA1OjMwIiB0eG49Ik9UUDQxYjE5MTg2MGFhZmNhZ2dmNjU2NTIzNTY2ODdERlQiIHR5cGU9IkEiIHVpZD0iNjM2NDg3NTk4NTQ4IiB2ZXI9IjIuNSI+PE9wdHMgY2g9IjAwIi8+PC9PdHA+';

    private static function expectedHash(): string
    {
        return base64_encode((string) openssl_encrypt(
            hash('sha256', self::SAMPLE_REQ_DATA),
            'AES-256-CBC',
            self::SAMPLE_TOKEN,
            OPENSSL_RAW_DATA,
            '0000000000000000',
        ));
    }

    public function test_req_hash_follows_the_csc_recipe(): void
    {
        $this->assertSame(self::expectedHash(), (new CscEnvelopeCodec(self::SAMPLE_TOKEN))->hash(self::SAMPLE_REQ_DATA));
    }

    public function test_verify_accepts_the_sample_and_refuses_tampering(): void
    {
        $codec = new CscEnvelopeCodec(self::SAMPLE_TOKEN);

        $this->assertTrue($codec->verify(self::SAMPLE_REQ_DATA, self::expectedHash()));
        $this->assertFalse($codec->verify('tampered', self::expectedHash()));
        $this->assertFalse($codec->verify(self::SAMPLE_REQ_DATA, null));
    }

    public function test_res_code_10_means_answered_not_authenticated(): void
    {
        $envelope = CscResponseEnvelope::fromArray([
            'res_code' => '10',
            'res_data' => base64_encode('<?xml version="1.0"?><OtpRes txn="41b1" err="952" ret="n"/>'),
        ]);

        $this->assertTrue($envelope->wasProcessed());
        $this->assertStringContainsString('ret="n"', (string) $envelope->decodedData());
        $this->assertFalse(CscResponseEnvelope::fromArray(['res_code' => '10', 'res_data' => null])->wasProcessed());
        $this->assertNull(CscResponseEnvelope::fromArray(['res_code' => '10', 'res_data' => '!!!'])->decodedData());
    }

    public function test_client_ts_carries_the_ist_offset(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+05:30$/',
            (new CscEnvelopeCodec(self::SAMPLE_TOKEN))->timestamp(),
        );
    }
}
