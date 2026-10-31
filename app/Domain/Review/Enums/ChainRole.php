<?php

declare(strict_types=1);

namespace App\Domain\Review\Enums;

/**
 * What a DPC is within its correction chain (architect Directive 4, M8 Phase 2).
 *
 * Three roles, not two: a superseded **Review** and the **Original** are both
 * "no longer the operative DPC", but they are not the same thing. The original
 * is what the department first decided; a superseded Review is a correction
 * that was itself corrected. Collapsing them would lose the distinction a
 * tribunal asks about first — *what did you decide, and what did you change?*
 *
 * The role is **derived from position in the chain**, not from whether
 * supersession has legally taken effect. That distinction belongs to Phase 3,
 * which sets `superseded_at`; this is the structural view, available now.
 */
enum ChainRole: string
{
    /** The DPC the chain began with. Never a Review. */
    case Original = 'original';

    /** The newest Review — the operative DPC in this chain. */
    case ActiveReview = 'active_review';

    /** A Review that a later Review has replaced. */
    case SupersededReview = 'superseded_review';

    public function label(): string
    {
        return __("ui.enum.chain_role.{$this->value}");
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Original => 'text-bg-secondary',
            self::ActiveReview => 'text-bg-primary',
            self::SupersededReview => 'text-bg-light text-dark',
        };
    }

    /**
     * Whether this DPC is the one now in force. Exactly one node in a chain
     * is — the original only while nothing has reviewed it.
     */
    public function isOperative(): bool
    {
        return $this === self::ActiveReview;
    }
}
