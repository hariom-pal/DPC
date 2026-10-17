<?php

declare(strict_types=1);

namespace App\Domain\Master\Services;

use App\Domain\Master\Support\MasterDefinition;
use App\Domain\Master\Support\MasterRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Search, CSV export and bulk upload for the reference masters
 * (business instruction, 2026-09-06).
 *
 * One implementation for every master in {@see MasterRegistry},
 * driven by that master's {@see MasterDefinition}. See the registry's own docblock
 * for why the versioned instruments are not among them.
 *
 * ### The upload is an **update-or-create**, never a replace
 *
 * A row whose identifying columns match one already in the master updates it;
 * anything else is inserted. Nothing is deleted, ever. A spreadsheet is an easy
 * thing to upload half of, and a master that emptied itself because somebody
 * uploaded the wrong file would take every dropdown in the portal with it. A row is
 * withdrawn by setting its status to Inactive — which the upload *can* do, because
 * `status` is in every template.
 *
 * ### It is all-or-nothing
 *
 * The whole file is validated before anything is written, and the write runs in one
 * transaction. A file that is right for two hundred rows and wrong for the two
 * hundred and first leaves the master untouched, and says which line was wrong.
 */
class MasterDataPortabilityService
{
    /**
     * Narrow a listing by what the officer typed.
     *
     * `LIKE %term%` across the master's searchable columns. Not full-text: these are
     * tables of tens to hundreds of rows, an index would never be consulted, and
     * matching mid-word is what somebody searching a list of post names expects.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @return Builder<TModel>
     */
    public function search(Builder $query, ?string $term, array $columns): Builder
    {
        $term = trim((string) $term);

        if ($term === '' || $columns === []) {
            return $query;
        }

        // `\` first, or it would escape the escapes added after it.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        return $query->where(function (Builder $inner) use ($columns, $escaped): void {
            foreach ($columns as $column) {
                $inner->orWhere($column, 'like', '%'.$escaped.'%');
            }
        });
    }

    /**
     * The master, as the officer is currently filtering it, as a CSV download.
     *
     * **Streamed**, so a master of any size costs one row of memory rather than all
     * of them, and **chunked** for the same reason.
     *
     * The file uses the same headers the upload template does, so an export can be
     * edited and uploaded back — which is the ordinary way somebody corrects fifty
     * rows at once.
     *
     * @param  Builder<Model>  $query
     */
    public function export(MasterDefinition $definition, Builder $query): StreamedResponse
    {
        $filename = $definition->key.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($definition, $query): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // Excel reads a CSV as the system codepage unless the file says
            // otherwise, and half of these masters carry Hindi. Without the byte
            // order mark every Devanagari name opens as mojibake.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $definition->headers());

            $query->chunk(500, function ($rows) use ($handle, $definition): void {
                foreach ($rows as $row) {
                    fputcsv($handle, $this->line($definition, $row));
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The blank template, with a couple of filled example rows.
     *
     * **The examples are part of the point.** A header-only file leaves somebody
     * guessing whether a status is `active` or `Active`, and whether a district is
     * named or numbered; two real rows answer both without a manual.
     */
    public function template(MasterDefinition $definition): StreamedResponse
    {
        return response()->streamDownload(function () use ($definition): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $definition->headers());

            foreach ($definition->sample as $row) {
                fputcsv($handle, array_map(
                    static fn (string $header): string => (string) ($row[$header] ?? ''),
                    $definition->headers(),
                ));
            }

            fclose($handle);
        }, $definition->key.'-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Load a filled-in template.
     *
     * @param  list<array<string, string>>  $rows  the parsed CSV, header-keyed
     * @return array{created: int, updated: int}
     *
     * @throws MasterImportException when any row is unusable — nothing is written
     */
    public function import(MasterDefinition $definition, array $rows): array
    {
        if ($rows === []) {
            throw new MasterImportException([(string) __('admin.master_data.errors.empty_file')]);
        }

        $errors = [];
        $prepared = [];

        foreach ($rows as $index => $row) {
            // +2: the header is line 1, and a spreadsheet counts from 1 — so this is
            // the line number the officer sees in their own file.
            $line = $index + 2;
            $attributes = [];

            foreach ($definition->columns as $header => $attribute) {
                $value = trim((string) ($row[$header] ?? ''));

                if ($value === '' && in_array($header, $definition->required, true)) {
                    $errors[] = (string) __('admin.master_data.errors.missing', ['line' => $line, 'column' => $header]);

                    continue;
                }

                if (isset($definition->lookups[$attribute])) {
                    if ($value === '') {
                        $attributes[$attribute] = null;

                        continue;
                    }

                    $id = $this->resolve($definition->lookups[$attribute], $value);

                    if ($id === null) {
                        $errors[] = (string) __('admin.master_data.errors.unknown', [
                            'line' => $line, 'column' => $header, 'value' => $value,
                        ]);

                        continue;
                    }

                    $attributes[$attribute] = $id;

                    continue;
                }

                $attributes[$attribute] = $value === '' ? null : $value;
            }

            // A status nobody filled in is `active`: somebody uploading rows means to
            // use them. An unrecognised one is refused rather than guessed.
            $attributes['status'] = $this->status($attributes['status'] ?? null, $line, $errors);

            $prepared[] = $attributes;
        }

        if ($errors !== []) {
            throw new MasterImportException($errors);
        }

        return DB::transaction(function () use ($definition, $prepared): array {
            $created = 0;
            $updated = 0;

            /** @var Model $model */
            $model = new $definition->model;

            foreach ($prepared as $attributes) {
                $query = $model->newQuery();

                /*
                 | Matched case-insensitively, on every database. MySQL's collation
                 | already compares `FRESH` and `fresh` as equal and SQLite does not,
                 | so the same file used to update a row on one and duplicate it on
                 | the other.
                 */
                foreach ($definition->unique as $key) {
                    $value = $attributes[$key] ?? null;

                    $value === null
                        ? $query->whereNull($key)
                        : $query->whereRaw('LOWER('.$model->getConnection()->getQueryGrammar()->wrap($key).') = ?', [Str::lower((string) $value)]);
                }

                $existing = $query->first();

                if ($existing === null) {
                    $model->newQuery()->create($attributes);
                    $created++;

                    continue;
                }

                // The identifying columns keep their stored spelling: an upload that
                // matched `fresh` by `FRESH` must not rewrite the code other screens
                // and seeded references compare against.
                foreach ($definition->unique as $key) {
                    unset($attributes[$key]);
                }

                $existing->fill($attributes)->save();
                $updated++;
            }

            return ['created' => $created, 'updated' => $updated];
        });
    }

    /**
     * A CSV name resolved to the id it stands for.
     *
     * Case-insensitive, because a spreadsheet typed by hand will not match the
     * master's capitalisation and refusing it would help nobody.
     */
    private function resolve(string $lookup, string $value): ?int
    {
        [$table, $column] = explode('.', $lookup, 2);

        $id = DB::table($table)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER('.$column.') = ?', [Str::lower($value)])
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  list<string>  $errors
     */
    private function status(?string $value, int $line, array &$errors): string
    {
        if ($value === null || $value === '') {
            return 'active';
        }

        $normalised = Str::lower($value);

        if (! in_array($normalised, ['active', 'inactive'], true)) {
            $errors[] = (string) __('admin.master_data.errors.status', ['line' => $line, 'value' => $value]);

            return 'active';
        }

        return $normalised;
    }

    /**
     * One exported row, in the template's column order.
     *
     * A `belongsTo` id is written back as the **name** it was imported by, so an
     * export is a file the upload will accept. Enums are written as their stored
     * value, and dates as `Y-m-d`.
     *
     * @return list<string>
     */
    private function line(MasterDefinition $definition, Model $row): array
    {
        $line = [];

        foreach ($definition->columns as $attribute) {
            $value = $row->getAttribute($attribute);

            if (isset($definition->lookups[$attribute])) {
                [$table, $column] = explode('.', $definition->lookups[$attribute], 2);
                $value = $value === null ? null : DB::table($table)->where('id', $value)->value($column);
            }

            $line[] = match (true) {
                $value === null => '',
                $value instanceof \BackedEnum => (string) $value->value,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                is_bool($value) => $value ? '1' : '0',
                default => (string) $value,
            };
        }

        return $line;
    }
}
