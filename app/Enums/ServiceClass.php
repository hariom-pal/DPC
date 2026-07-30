<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statutory service classes (Class I / II / III). Kept as an enum per the
 * architect decision — a fixed constant, not admin-editable.
 */
enum ServiceClass: string
{
    case I = 'I';
    case II = 'II';
    case III = 'III';

    public function label(): string
    {
        return 'Class-'.$this->value;
    }
}
