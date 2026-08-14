<?php

declare(strict_types=1);

namespace App\Domain\Proceeding\Documents;

use App\Domain\Proceeding\Enums\DocumentType;
use App\Domain\Proceeding\Models\DpcProceeding;

/**
 * Abstraction over how a proceedings document is rendered — M7 Phase 4.
 *
 * The Commission's decision is made at approval; a renderer only expresses it.
 * Keeping that behind a contract is what lets **PDF replace HTML later without
 * touching the approval path** (architect ruling AQ-3, and Directive 3: the
 * document service stays independent of approval).
 *
 * Business logic depends on this contract, never on a concrete renderer — the
 * same Provider Pattern every external integration follows.
 */
interface DocumentRenderer
{
    /**
     * A short, stable identifier for this renderer, recorded on every document
     * it produces. A document must be able to say what produced it: "rendered
     * by html-v1" is answerable years later; "rendered by the renderer" is not.
     */
    public function version(): string;

    /**
     * The media type of what `render()` returns.
     */
    public function contentType(): string;

    /**
     * The file extension documents from this renderer carry.
     */
    public function extension(): string;

    public function render(DpcProceeding $proceeding, DocumentType $type): string;
}
