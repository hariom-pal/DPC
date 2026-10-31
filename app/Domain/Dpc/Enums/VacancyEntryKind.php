<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Enums;

/**
 * The rows of a Vacancy Management record (decisions of 2026-10-30):
 *
 * DeputationIn: (B1) an officer of another department working here on deputation.
 * They are in the working strength; their post falls vacant when the deputation
 * ends inside the Vacancy Year.
 *
 * Deputation: (B2) an officer of ours on deputation at another department. They are
 * in the working strength; their post is a vacancy when the deputation is more than
 * one year by 31 December and no promotion has been made against it (rule 2.3).
 *
 * Anticipated: (C) a retirement or a promotion to a higher post during the year.
 *
 * (A) Clear is never entered: it is sanctioned − working. `Existing` belongs to
 * records kept before 2026-10-30 and is no longer offered.
 */
enum VacancyEntryKind: string
{
    case Existing = 'existing';
    case DeputationIn = 'deputation_in';
    case Deputation = 'deputation';
    case Anticipated = 'anticipated';

    public function label(): string
    {
        return __("vacancymaster.kinds.{$this->value}");
    }
}
