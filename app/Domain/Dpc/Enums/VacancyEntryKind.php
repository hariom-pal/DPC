<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Enums;

/**
 * Existing: a vacancy already open on 1 January. It is part of the opening position
 * (sanctioned − working) by definition, so it explains that figure and never adds
 * to it.
 *
 * Anticipated: a vacancy arising during the Vacancy Year — (C) on the screen.
 *
 * Deputation (2026-10-30, MoM of 2026-10-29): (B) Clear Deputation Vacancy — one
 * officer of the working strength on deputation at another department, whose post
 * is a vacancy under rule 2.3.
 *
 * (A) Clear is never entered: it is sanctioned − working. `Existing` belongs to
 * records kept before 2026-10-30 and is no longer offered.
 */
enum VacancyEntryKind: string
{
    case Existing = 'existing';
    case Deputation = 'deputation';
    case Anticipated = 'anticipated';

    public function label(): string
    {
        return __("vacancymaster.kinds.{$this->value}");
    }
}
