<?php

declare(strict_types=1);

namespace App\Domain\Authentication\Exceptions;

use RuntimeException;

/**
 * The OTP could not be handed to the mail server (SMTP down, misconfigured, or
 * refusing the address). The code is withdrawn, so a code nobody received is never
 * left valid, and the person is told to try again.
 */
final class OtpDeliveryFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct((string) __('authui.otp.delivery_failed'));
    }
}
