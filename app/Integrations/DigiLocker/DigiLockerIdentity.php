<?php

declare(strict_types=1);

namespace App\Integrations\DigiLocker;

use Illuminate\Support\Carbon;

/**
 * The identity attributes a DigiLocker account returned, normalised (2026-10-27).
 *
 * `gender` is `M`, `F` or `T` (DigiLocker's values). `reference` is the
 * DigiLocker account id when the provider supplies one; whether it is a stable
 * per-person identifier is not confirmed (audit Q-API-1), so nothing relies on it.
 */
final readonly class DigiLockerIdentity
{
    public function __construct(
        public string $name,
        public Carbon $dateOfBirth,
        public string $gender,
        public bool $aadhaarVerified,
        public ?string $reference = null,
    ) {}

    /**
     * @return array{name: string, date_of_birth: string, gender: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'date_of_birth' => $this->dateOfBirth->toDateString(), 'gender' => $this->gender];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['name' => '[redacted]', 'aadhaarVerified' => $this->aadhaarVerified];
    }
}
