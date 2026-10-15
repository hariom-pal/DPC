<?php

declare(strict_types=1);

namespace App\Domain\Master\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * One reference master, described once, for the three things every admin master
 * screen now offers: **search**, **CSV export** and **bulk upload from a sample
 * CSV** (business instruction, 2026-09-06).
 *
 * **A registry rather than three methods per controller.** There are two dozen
 * master screens and they differ only in which model and which columns they carry.
 * Written per controller, "export to CSV" would be two dozen near-identical methods
 * that drift, and the one that drifts is the one nobody re-reads. Here a master is
 * a row of data, and adding a new one to all three features is adding an entry to
 * {@see MasterRegistry}.
 *
 * @phpstan-type ColumnMap array<string, string>
 */
final readonly class MasterDefinition
{
    /**
     * @param  class-string<Model>  $model  the master's Eloquent model
     * @param  ColumnMap  $columns  CSV header => model attribute
     * @param  list<string>  $required  CSV headers a row must carry a value for
     * @param  list<string>  $searchable  columns a search term is matched against
     * @param  list<string>  $unique  the attributes that identify a row for update-or-create
     * @param  array<string, string>  $lookups  attribute => `table.column` a CSV **name** is resolved through
     * @param  list<array<string, string>>  $sample  rows written into the downloadable template
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $model,
        public array $columns,
        public array $required,
        public array $searchable,
        public array $unique,
        public array $lookups = [],
        public array $sample = [],
    ) {}

    /**
     * The CSV header row.
     *
     * @return list<string>
     */
    public function headers(): array
    {
        return array_keys($this->columns);
    }
}
