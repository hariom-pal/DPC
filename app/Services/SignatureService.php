<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Models\SignEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * OTP step-up "signature" for legally significant actions.
 *
 * Replaces the PPT's Aadhaar-OTP signing statement (per the approved Q1
 * override): a fresh OTP is issued to the actor's verified channel and, once
 * confirmed, an immutable SignEvent is recorded as the record of intent for
 * the specific record being signed. Wired to business actions in later
 * milestones; provided here as the foundation.
 */
class SignatureService
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * Issue a signing OTP to the actor on the chosen channel.
     */
    public function requestSignature(User $user, OtpChannel $channel): void
    {
        $identifier = $this->identifierFor($user, $channel);

        $this->otp->issue($identifier, $channel, OtpPurpose::Signature);
    }

    /**
     * Confirm the signing OTP and persist the SignEvent against the record.
     */
    public function confirmSignature(User $user, Model $signable, OtpChannel $channel, string $code, Request $request): ?SignEvent
    {
        $identifier = $this->identifierFor($user, $channel);

        if (! $this->otp->verify($identifier, OtpPurpose::Signature, $code)) {
            return null;
        }

        $event = new SignEvent([
            'signable_type' => $signable->getMorphClass(),
            'signable_id' => $signable->getKey(),
            'channel' => $channel->value,
            'otp_identifier' => $identifier,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'signed_at' => now(),
        ]);
        $event->user()->associate($user);
        $event->save();

        return $event;
    }

    private function identifierFor(User $user, OtpChannel $channel): string
    {
        $identifier = $channel === OtpChannel::Email ? $user->email : $user->mobile;

        if ($identifier === null) {
            throw new \RuntimeException('User has no verified '.$channel->value.' identifier for signing.');
        }

        return $identifier;
    }
}
