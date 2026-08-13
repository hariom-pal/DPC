<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Enums;

/**
 * DPC committee member capacity (PPT S15 "CAPACITY" column).
 */
enum CommitteeCapacity: string
{
    case Chairman = 'chairman';
    case Member = 'member';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
