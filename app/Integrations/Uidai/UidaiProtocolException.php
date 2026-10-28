<?php

declare(strict_types=1);

namespace App\Integrations\Uidai;

use RuntimeException;

/**
 * A UIDAI document could not be built or read: a malformed response, a
 * transaction identifier UIDAI would refuse, an OTP that is not six digits.
 *
 * Internal to the integration. {@see UidaiAadhaarOtpProvider} catches it and
 * answers "not sent" / "not verified" with a message — it never reaches the
 * caller. Messages are written so they can never contain the Aadhaar number
 * or the OTP.
 */
final class UidaiProtocolException extends RuntimeException {}
