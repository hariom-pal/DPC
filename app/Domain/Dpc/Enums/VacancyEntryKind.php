<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Enums;

/**
 * Existing: a vacancy already open on 1 January. It is part of the opening position
 * (sanctioned − working) by definition, so it explains that figure and never adds
 * to it.
 *
 * Anticipated: a vacancy arising during the Vacancy Year. It adds only when it is
 * eligible and the Administrator has reconciled it as counted in the 1 January
 * working strength.
 */
enum VacancyEntryKind: string
{
    case Existing = 'existing';
    case Anticipated = 'anticipated';

    public function label(): string
    {
        return __("vacancymaster.kinds.{$this->value}");
    }
}
