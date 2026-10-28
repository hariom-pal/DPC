<?php

declare(strict_types=1);

namespace App\Domain\Signature\Services;

use App\Domain\Authentication\Models\User;
use App\Domain\Identity\Services\IdentityMatcher;
use App\Domain\Signature\Exceptions\SignatureVerificationFailed;
use App\Domain\Signature\Models\Signature;
use App\Domain\Signature\Models\SignatureAttempt;
use App\Domain\Signature\SignatureRequest;
use App\Domain\Signature\SignatureResult;
use App\Enums\SignatureStatus;
use App\Integrations\Contracts\SignatureProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Signs a business document through the configured SignatureProvider, stores
 * the signed file on the configured disk (S3 in production) and persists the
 * signature metadata (provider, transaction reference, certificate details,
 * timestamp) for audit.
 *
 * Business modules depend on this service only — never on OTP or a concrete
 * provider.
 */
class SignatureService
{
    public function __construct(private readonly SignatureProvider $provider) {}

    /**
     * Sign as the AO or AH of a DPC (2026-10-27): verified against THIS
     * transaction, every time — the provider must succeed, echo this request's
     * one-time reference and the digest of this document, assert a transaction
     * never seen before, and assert an authenticated signer name that matches the
     * portal-registered name. No earlier verification stands in for it.
     *
     * Name policy: {@see IdentityMatcher::normaliseName()} — case, spacing, full
     * stops and leading salutations are ignored; any other difference (initials,
     * spelling, script) is a mismatch.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws SignatureVerificationFailed with nothing stored
     */
    public function signVerified(User $signer, Model $signable, string $documentContents, string $documentName, array $context = []): Signature
    {
        $signature = $this->sign($signer, $signable, $documentContents, $documentName, $context, verify: true);

        // Every attempt is on record (2026-10-28); a failed one is recorded by the
        // caller once its transaction has rolled back.
        app(SignatureAttemptLog::class)->record($signer, $signable, (string) ($context['action'] ?? 'sign'), SignatureAttempt::OUTCOME_VERIFIED, $signature->signer_name);

        return $signature;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function sign(User $signer, Model $signable, string $documentContents, string $documentName, array $context = [], bool $verify = false): Signature
    {
        return DB::transaction(function () use ($signer, $signable, $documentContents, $documentName, $context, $verify): Signature {
            $request = new SignatureRequest(
                signer: $signer,
                signable: $signable,
                documentContents: $documentContents,
                documentName: $documentName,
                context: $context,
                reference: Str::uuid()->toString(),
            );

            try {
                $result = $this->provider->sign($request);
            } catch (Throwable $e) {
                if (! $verify) {
                    throw $e;
                }

                throw new SignatureVerificationFailed(SignatureVerificationFailed::ESIGN_FAILED);
            }

            if ($verify) {
                $this->verify($request, $result);
            }

            $disk = (string) config('dpc.signature.disk', 'local');
            $path = 'signatures/'.$signable->getMorphClass().'/'.$signable->getKey().'/'
                .Str::uuid().'-'.$documentName;

            Storage::disk($disk)->put($path, $result->signedContents);

            $signature = new Signature([
                'signable_type' => $signable->getMorphClass(),
                'signable_id' => $signable->getKey(),
                'provider' => $result->provider,
                'status' => SignatureStatus::Signed->value,
                'transaction_reference' => $result->transactionReference,
                'certificate_subject' => $result->certificateSubject,
                'certificate_serial' => $result->certificateSerial,
                'certificate_issuer' => $result->certificateIssuer,
                'signer_name' => $result->signerName,
                'request_reference' => $request->reference,
                'signed_disk' => $disk,
                'signed_path' => $path,
                'meta' => $result->meta,
                'signed_at' => $result->signedAt,
            ]);
            $signature->user()->associate($signer);
            $signature->save();

            return $signature;
        });
    }

    /**
     * @throws SignatureVerificationFailed
     */
    private function verify(SignatureRequest $request, SignatureResult $result): void
    {
        if ($result->transactionReference === '' || $result->requestReference === null
            || ! hash_equals((string) $request->reference, $result->requestReference)
            || $result->documentDigest === null
            || ! hash_equals($request->documentDigest(), $result->documentDigest)) {
            throw new SignatureVerificationFailed(SignatureVerificationFailed::NOT_CORRELATED);
        }

        if (Signature::query()->where('provider', $result->provider)->where('transaction_reference', $result->transactionReference)->exists()) {
            throw new SignatureVerificationFailed(SignatureVerificationFailed::REPLAYED);
        }

        $asserted = trim((string) $result->signerName);

        if ($asserted === '' || IdentityMatcher::normaliseName($asserted) !== IdentityMatcher::normaliseName((string) $request->signer->name)) {
            throw new SignatureVerificationFailed(SignatureVerificationFailed::NAME_MISMATCH, $asserted !== '' ? $asserted : null);
        }
    }
}
