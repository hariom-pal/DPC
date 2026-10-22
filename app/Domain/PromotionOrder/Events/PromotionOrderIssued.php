<?php

declare(strict_types=1);

namespace App\Domain\PromotionOrder\Events;

use App\Domain\PromotionOrder\Models\DpcPromotionOrder;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A promotion order was issued (rule 4.3): its link goes to the officers it promotes
 * and to every member of the DPC committee.
 */
final class PromotionOrderIssued
{
    use Dispatchable;

    public function __construct(public readonly DpcPromotionOrder $order) {}
}
