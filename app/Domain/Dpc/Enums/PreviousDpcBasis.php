<?php

declare(strict_types=1);

namespace App\Domain\Dpc\Enums;

/**
 * What the working strength was reconciled against (Rule 1.1).
 */
enum PreviousDpcBasis: string
{
    /** A previous DPC for this post held through the portal. */
    case InSystem = 'in_system';

    /** A previous DPC held outside the portal; its minutes are uploaded. */
    case Offline = 'offline';

    /** The department states no DPC has been held for this post before. */
    case None = 'none';

    public function label(): string
    {
        return __("dpc.verification.previous_dpc.basis.{$this->value}");
    }
}
