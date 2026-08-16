<?php

declare(strict_types=1);

namespace App\Domain\Circulation\Documents;

use App\Domain\Circulation\Enums\CirculationDocumentType;
use App\Domain\Circulation\Models\DpcCirculation;

/**
 * **M9's own renderer contract, by architect ruling (AQ-22).**
 *
 * M7's `DocumentRenderer` is hard-typed to `DpcProceeding` and a Circulation
 * has none (AD-24), so it could not have been implemented here without either
 * passing the origin's proceeding — filing the note among the original DPC's
 * documents — or a fifth decorator in a container graph that has already
 * cycled once. M7's contract is neither modified nor implemented.
 *
 * Deliberately the same *shape* as M7's, so a `PdfCirculationDocumentRenderer`
 * can replace the HTML one later without touching the pipeline (the reasoning
 * behind AQ-3, applied here).
 */
interface CirculationDocumentRenderer
{
    /** The label stamped into every document this renderer produces. */
    public function version(): string;

    public function contentType(): string;

    public function extension(): string;

    public function render(DpcCirculation $circulation, CirculationDocumentType $type): string;
}
