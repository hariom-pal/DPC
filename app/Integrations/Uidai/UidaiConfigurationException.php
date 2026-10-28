<?php

declare(strict_types=1);

namespace App\Integrations\Uidai;

use RuntimeException;

/**
 * The live provider is switched on (FEATURE_UIDAI_AADHAAR_OTP) but a setting it
 * cannot work without is missing or unusable — the CSC token, the client id, the
 * UIDAI encryption certificate.
 *
 * A deployment defect, not something the officer can fix: the provider logs it
 * at `error` and answers the officer with a "temporarily unavailable" message.
 * The message names the setting, never a secret's value.
 */
final class UidaiConfigurationException extends RuntimeException {}
