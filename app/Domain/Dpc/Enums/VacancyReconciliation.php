<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Enums;

/**
 * The Administrator's answer, for an anticipated vacancy, to the one question the
 * portal cannot answer from count-only data: was the officer who will leave counted
 * in the 1 January working strength?
 *
 * Yes — the chair is filled on 1 January and empties during the year, so it adds.
 * No — the chair is already empty on 1 January, so it is inside the opening vacancy
 * and adding it again would count it twice.
 */
enum VacancyReconciliation: string
{
    case CountedInWorking = 'counted_in_working';
    case AlreadyInOpening = 'already_in_opening';

    public function label(): string
    {
        return __("vacancymaster.reconciliation.{$this->value}");
    }
}
