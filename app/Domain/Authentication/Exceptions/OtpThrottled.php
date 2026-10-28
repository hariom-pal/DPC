<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Exceptions;

use RuntimeException;

/**
 * A new OTP was asked for too soon after the last one, or too often within the
 * hour. `retryAfterSeconds` says when the next request can succeed.
 */
final class OtpThrottled extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds, public readonly bool $hourlyLimit = false)
    {
        parent::__construct($hourlyLimit
            ? (string) __('authui.otp.hourly_limit')
            : (string) __('authui.otp.cooldown', ['seconds' => $retryAfterSeconds]));
    }
}
