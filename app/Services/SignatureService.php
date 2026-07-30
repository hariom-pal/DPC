<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SignatureStatus;
use App\Models\Signature;
use App\Models\User;
use App\Signature\Contracts\SignatureProvider;
use App\Signature\SignatureRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
     * @param  array<string, mixed>  $context
     */
    public function sign(User $signer, Model $signable, string $documentContents, string $documentName, array $context = []): Signature
    {
        return DB::transaction(function () use ($signer, $signable, $documentContents, $documentName, $context): Signature {
            $result = $this->provider->sign(new SignatureRequest(
                signer: $signer,
                signable: $signable,
                documentContents: $documentContents,
                documentName: $documentName,
                context: $context,
            ));

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
}
