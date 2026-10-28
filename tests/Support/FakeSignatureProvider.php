<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Signature\SignatureRequest;
use App\Domain\Signature\SignatureResult;
use App\Integrations\Contracts\SignatureProvider;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A scriptable eSign provider for tests (2026-10-27). It proves the portal's
 * handling of a provider's answer — never that a real eSign provider answers so.
 */
final class FakeSignatureProvider implements SignatureProvider
{
    public const OK = 'ok';

    public const OTHER_NAME = 'other_name';

    public const FAIL = 'fail';

    public const WRONG_REFERENCE = 'wrong_reference';

    public const WRONG_DOCUMENT = 'wrong_document';

    public const REPLAY = 'replay';

    public string $mode = self::OK;

    public string $assertedName = 'Someone Else Entirely';

    public int $calls = 0;

    public function name(): string
    {
        return 'fake';
    }

    public function sign(SignatureRequest $request): SignatureResult
    {
        $this->calls++;

        if ($this->mode === self::FAIL) {
            throw new RuntimeException('eSign gateway rejected the request');
        }

        return new SignatureResult(
            provider: $this->name(),
            transactionReference: $this->mode === self::REPLAY ? 'FAKE-REPLAYED-TXN' : 'FAKE-'.Str::upper(Str::random(16)),
            signedContents: $request->documentContents."\n--- SIGNED (FAKE) ---",
            signedAt: now(),
            certificateSubject: 'CN=Fake',
            signerName: $this->mode === self::OTHER_NAME ? $this->assertedName : $request->signer->name,
            requestReference: $this->mode === self::WRONG_REFERENCE ? Str::uuid()->toString() : $request->reference,
            documentDigest: $this->mode === self::WRONG_DOCUMENT ? hash('sha256', 'another document') : $request->documentDigest(),
        );
    }
}
