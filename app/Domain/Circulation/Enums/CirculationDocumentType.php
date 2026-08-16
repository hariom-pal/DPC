<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Enums;

/**
 * The two documents a circulation produces (PPT S45, S48) — M9 Phase 3.
 *
 * **M9's own type, by architect ruling (AQ-22).** M7's `DocumentType` is
 * frozen and is neither opened nor read here: an enum case cannot be
 * decorated, and reusing M7's would have filed a circulation note among the
 * original DPC's documents with nothing to tell it apart.
 */
enum CirculationDocumentType: string
{
    /** The परिभ्रमण टीप — the prescribed form S45 sends to the Commission for signature. */
    case CirculationNote = 'circulation_note';

    /** S48's second table: the DPC's result with the released officers' `CIR` cleared. */
    case RevisedPromotionList = 'revised_promotion_list';

    public function label(): string
    {
        return match ($this) {
            self::CirculationNote => 'Circulation Note (परिभ्रमण टीप)',
            self::RevisedPromotionList => 'Revised Promotion List',
        };
    }

    public function view(): string
    {
        return match ($this) {
            self::CirculationNote => 'documents.circulation-note',
            self::RevisedPromotionList => 'documents.revised-promotion-list',
        };
    }

    public function filename(): string
    {
        return match ($this) {
            self::CirculationNote => 'circulation-note.html',
            self::RevisedPromotionList => 'revised-promotion-list.html',
        };
    }
}
