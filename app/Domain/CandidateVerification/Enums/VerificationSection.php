<?php

declare(strict_types=1);

namespace App\Domain\CandidateVerification\Enums;

/**
 * The four sections a candidate verifies (2026-11-19). IPR and the Property File are
 * one section. Each names the Employee Details section the AO corrects it in.
 */
enum VerificationSection: string
{
    case Seniority = 'seniority';
    case Cr = 'cr';
    case Integrity = 'integrity';
    case Ipr = 'ipr';

    public function label(): string
    {
        return (string) __('candidate.verification.sections.'.$this->value);
    }

    /** The Employee Details section an AO opens to correct this one. */
    public function employeeSection(): string
    {
        return match ($this) {
            self::Seniority => 'basic',
            self::Cr, self::Integrity => 'cr',
            self::Ipr => 'property',
        };
    }

    /** @return list<string> */
    public function categories(): array
    {
        return array_values((array) config('dpc.candidate_verification.categories.'.$this->value, []));
    }

    public function next(): ?self
    {
        return match ($this) {
            self::Seniority => self::Cr,
            self::Cr => self::Integrity,
            self::Integrity => self::Ipr,
            self::Ipr => null,
        };
    }
}
