<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every feature flag in the system, as a type (architect ruling,
 * 2026-08-13).
 *
 * Business logic asks `Feature::Hrms->enabled()`, never
 * `config('features.hrms')`. The difference matters: a mistyped config key
 * returns null, which is falsy, so an integration would silently switch off
 * and nothing would fail — the worst possible failure mode for a flag whose
 * whole job is to be trustworthy. A mistyped enum case is a fatal error.
 *
 * Adding a flag means adding a case here and a line in `config/features.php`.
 * The admin Integration Status screen enumerates these cases, so a new flag
 * appears there automatically rather than needing to be registered twice.
 */
enum Feature: string
{
    case Hrms = 'hrms';
    case CscDigiSign = 'csc_digisign';
    case SmsGateway = 'sms_gateway';
    case EmailGateway = 'email_gateway';
    case S3Storage = 's3_storage';
    case LgdSync = 'lgd_sync';
    case Notifications = 'notifications';
    case UidaiAadhaarOtp = 'uidai_aadhaar_otp';
    case DigiLocker = 'digilocker';

    public function enabled(): bool
    {
        return (bool) config('features.'.$this->value);
    }

    public function disabled(): bool
    {
        return ! $this->enabled();
    }

    /**
     * The environment variable that controls this flag, shown on the
     * Integration Status screen so an operator can act on what they see.
     */
    public function envKey(): string
    {
        return 'FEATURE_'.strtoupper($this->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::Hrms => 'HRMS',
            self::CscDigiSign => 'CSC DigiSign / eSign',
            self::SmsGateway => 'SMS Gateway',
            self::EmailGateway => 'E-mail Gateway',
            self::S3Storage => 'S3 Object Storage',
            self::LgdSync => 'LGD Directory Sync',
            self::Notifications => 'Notifications',
            self::UidaiAadhaarOtp => 'UIDAI Aadhaar OTP',
            self::DigiLocker => 'DigiLocker identity verification',
        };
    }

    /**
     * What the system does while this flag is off. Documented per flag
     * because "disabled" must never mean "broken" — every integration has a
     * defined degraded mode, and this is where it is stated.
     */
    public function fallbackBehaviour(): string
    {
        return match ($this) {
            self::Hrms => 'Officer records are entered manually or imported from the Excel templates. No workflow is affected.',
            self::CscDigiSign => 'Non-production signs through the mock provider. Production refuses to sign and reports a business error — it never falls back to the mock.',
            self::SmsGateway => 'Messages are written to the application log instead of being sent.',
            self::EmailGateway => "Mail uses the framework's configured non-delivering driver.",
            self::S3Storage => 'Signed documents and uploaded imports are stored on the local filesystem disk.',
            self::LgdSync => 'The seeded Chhattisgarh district list is served.',
            self::Notifications => 'No workflow or OTP notifications are dispatched.',
            self::UidaiAadhaarOtp => 'Department registration authenticates Aadhaar against the mock provider: no code is sent, and the configured mock code is accepted.',
            self::DigiLocker => 'Officer identity is checked against the simulated DigiLocker (local and testing only; refused in production).',
        };
    }

    /**
     * Whether this integration is a hard external dependency that is not
     * available yet. Used by the Integration Status screen to distinguish
     * "switched off" from "cannot be switched on yet".
     */
    public function awaitingExternalApi(): bool
    {
        return match ($this) {
            self::Hrms, self::CscDigiSign, self::DigiLocker => true,
            default => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
