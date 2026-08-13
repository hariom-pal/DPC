<?php

declare(strict_types=1);

namespace App\Domain\Import\Support;

use Maatwebsite\Excel\Concerns\ToArray;

/**
 * Reads the first worksheet as a plain array of rows, headers included.
 *
 * Deliberately not using `WithHeadingRow`: the S18 template has dynamic
 * per-year columns (CR 2012, LOYALTY 2013, …) that have to be matched by
 * pattern, so the header row is read as data and interpreted by
 * ExcelImportService rather than by the package.
 */
class RawSheetImport implements ToArray
{
    /**
     * @var array<int, array<int, mixed>>
     */
    public array $rows = [];

    /**
     * @param  array<int, array<int, mixed>>  $array
     */
    public function array(array $array): void
    {
        $this->rows = $array;
    }
}
