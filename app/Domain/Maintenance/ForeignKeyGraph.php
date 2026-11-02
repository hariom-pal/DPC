<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * What the cleanups share: the schema's own foreign keys (to delete child before
 * parent with the checks ON) and the stored files rows point at (removed only after
 * the commit, and only when no remaining row still names them).
 *
 * Used by `dpc:uat-cleanup` and `dpc:purge-dpc-data`.
 */
final class ForeignKeyGraph
{
    /**
     * Tables in an order where no table is deleted before a table that still
     * references it.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function deleteOrder(array $tables): array
    {
        $references = [];

        foreach ($tables as $table) {
            foreach ($this->foreignKeys($table) as $fk) {
                $parent = $fk['foreign_table'];

                if ($parent !== $table && in_array($parent, $tables, true)
                    && DB::table($table)->whereNotNull($fk['columns'][0])->exists()) {
                    $references[$parent][$table] = true;   // $table must go before $parent
                }
            }
        }

        $order = [];
        $remaining = $tables;

        while ($remaining !== []) {
            $ready = array_values(array_filter(
                $remaining,
                fn (string $t): bool => array_intersect(array_keys($references[$t] ?? []), $remaining) === [],
            ));

            if ($ready === []) {
                throw new RuntimeException('Foreign keys form a cycle among: '.implode(', ', $remaining));
            }

            $order = [...$order, ...$ready];
            $remaining = array_values(array_diff($remaining, $ready));
        }

        return $order;
    }

    /**
     * Nullable references between tables that are all being emptied go to NULL, so a
     * cycle between them (or a row pointing at its own table) cannot stop the delete.
     *
     * @param  list<string>  $emptied
     * @return int references cleared
     */
    public function breakCycles(array $emptied): int
    {
        $cleared = 0;

        foreach ($emptied as $table) {
            foreach ($this->foreignKeys($table) as $fk) {
                if (in_array($fk['foreign_table'], $emptied, true) && $this->nullable($table, $fk['columns'][0])) {
                    $cleared += DB::table($table)->whereNotNull($fk['columns'][0])->update([$fk['columns'][0] => null]);
                }
            }
        }

        return $cleared;
    }

    /**
     * @return list<array{columns: list<string>, foreign_table: string, foreign_columns: list<string>, on_delete: string|null}>
     */
    public function foreignKeys(string $table): array
    {
        return array_map(fn (array $fk): array => [
            'columns' => array_values($fk['columns']),
            'foreign_table' => (string) $fk['foreign_table'],
            'foreign_columns' => array_values($fk['foreign_columns']),
            'on_delete' => $fk['on_delete'] ?? null,
        ], Schema::getForeignKeys($table));
    }

    public function nullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $definition) {
            if ($definition['name'] === $column) {
                return (bool) $definition['nullable'];
            }
        }

        return false;
    }

    /**
     * The files the given rows name.
     *
     * @param  list<array{0: string, 1: string}>  $pairs  [disk column or `config:` key, path column]
     * @return list<array{disk: string, path: string}>
     */
    public function filesOf(Builder $rows, array $pairs): array
    {
        $files = [];

        foreach ($pairs as [$disk, $path]) {
            foreach ((clone $rows)->whereNotNull($path)->get() as $row) {
                $files[] = ['disk' => $this->diskOf($disk, $row), 'path' => (string) $row->{$path}];
            }
        }

        return $files;
    }

    /**
     * After the commit: delete each file unless a remaining row names it.
     *
     * @param  list<array{disk: string, path: string}>  $files
     * @param  array<string, list<array{0: string, 1: string}>>  $references  every table => its file columns
     * @return array{deleted: int, kept_shared: int, missing: int}
     */
    public function deleteFiles(array $files, array $references): array
    {
        $result = ['deleted' => 0, 'kept_shared' => 0, 'missing' => 0];

        foreach (array_unique($files, SORT_REGULAR) as $file) {
            if ($this->stillReferenced($file, $references)) {
                $result['kept_shared']++;

                continue;
            }

            $storage = Storage::disk($file['disk']);

            if (! $storage->exists($file['path'])) {
                $result['missing']++;

                continue;
            }

            $storage->delete($file['path']);
            $result['deleted']++;
        }

        return $result;
    }

    /**
     * @param  array{disk: string, path: string}  $file
     * @param  array<string, list<array{0: string, 1: string}>>  $references
     */
    private function stillReferenced(array $file, array $references): bool
    {
        foreach ($references as $table => $pairs) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($pairs as [$disk, $path]) {
                $query = DB::table($table)->where($path, $file['path']);

                if (! str_starts_with($disk, 'config:')) {
                    $query->where($disk, $file['disk']);
                } elseif ($this->diskOf($disk, null) !== $file['disk']) {
                    continue;
                }

                if ($query->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function diskOf(string $disk, ?object $row): string
    {
        if (str_starts_with($disk, 'config:')) {
            return (string) config(substr($disk, 7), 'local');
        }

        return $row === null ? 'local' : (string) ($row->{$disk} ?? 'local');
    }
}
