<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Enums;

/**
 * The two documents a concluded DPC produces (PPT S33–S34) — M7 Phase 4.
 *
 * They answer different questions and are deliberately separate. The
 * proceedings document records **what the Commission did** — who sat, what was
 * corrected and why, what was held. The promotion list records **what the
 * Commission decided** — the Main and Waiting lists. A department contesting a
 * promotion needs the second; a tribunal examining how it was reached needs
 * the first.
 */
enum DocumentType: string
{
    case Proceedings = 'proceedings';
    case PromotionList = 'promotion_list';

    public function label(): string
    {
        return match ($this) {
            self::Proceedings => 'DPC Proceedings',
            self::PromotionList => 'Promotion List (Main & Waiting)',
        };
    }

    public function view(): string
    {
        return match ($this) {
            self::Proceedings => 'documents.proceedings',
            self::PromotionList => 'documents.promotion-list',
        };
    }

    public function filename(): string
    {
        return match ($this) {
            self::Proceedings => 'dpc-proceedings.html',
            self::PromotionList => 'dpc-promotion-list.html',
        };
    }
}
