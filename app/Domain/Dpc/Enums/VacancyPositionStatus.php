<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Enums;

/**
 * Draft → Finalized, and Superseded once a later revision of the same position is
 * finalized. A finalized or superseded position is never edited.
 */
enum VacancyPositionStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Superseded = 'superseded';

    public function label(): string
    {
        return __("vacancymaster.status.{$this->value}");
    }
}
