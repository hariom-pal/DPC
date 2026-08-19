<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every schema identifier must fit MySQL's 64-character limit.
 *
 * **TD-24 step 1. This exists because its absence already cost a P0.**
 *
 * The suite runs on in-memory SQLite; the application runs on MySQL. SQLite has
 * no identifier-length limit, MySQL refuses anything over 64 characters with
 * error 1059 — so a migration whose *derived* foreign-key name came out at 65
 * characters passed **84 green tests** and could not be created by
 * `migrate:fresh` at all. `dpc_document_review_references` had never existed in
 * the development database, and four documents recorded the gate as green on a
 * table count taken before the failure.
 *
 * The check runs on whatever engine the suite uses, because it does not ask the
 * database what its limit is — it asks what the identifiers *are* and applies
 * MySQL's limit to them. That is the point: the guarantee has to hold on the
 * engine that does not enforce it.
 *
 * **Tables, columns and indexes are read from the live schema. Foreign keys are
 * read from the migration source, and that split is deliberate.** SQLite stores
 * foreign keys inline and **unnamed** — `Schema::getForeignKeys()` returns
 * `name => null` for every one of them — so the database cannot tell us whether
 * a constraint was named explicitly or left to Laravel's
 * `{table}_{columns}_foreign` derivation. Deriving unconditionally produces a
 * false positive on exactly the constraint that caused the P0, because that one
 * *is* now named explicitly. The migration source is the only place that
 * distinguishes the two, so that is where this half of the check looks.
 */
class SchemaIdentifierLengthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * MySQL's hard limit on any identifier — table, column, index or constraint.
     * Not configurable, and not a style preference.
     */
    private const MYSQL_MAX_IDENTIFIER_LENGTH = 64;

    public function test_every_schema_identifier_fits_mysqls_64_character_limit(): void
    {
        $offenders = [];
        $checked = 0;

        foreach (Schema::getTableListing() as $table) {
            // Laravel 12+ may qualify listed names with the connection's schema
            // (e.g. `main.users` on SQLite); the identifier MySQL would see is
            // the bare table name.
            $table = str_contains($table, '.') ? substr($table, (int) strrpos($table, '.') + 1) : $table;

            $checked++;
            $this->record($offenders, $table, "table `{$table}`");

            foreach (Schema::getColumnListing($table) as $column) {
                $checked++;
                $this->record($offenders, $column, "column `{$table}`.`{$column}`");
            }

            foreach (Schema::getIndexes($table) as $index) {
                $name = (string) ($index['name'] ?? '');

                // SQLite invents `sqlite_autoindex_*` for UNIQUE columns. Those
                // are the engine's, not ours, and have no MySQL counterpart.
                if ($name === '' || str_starts_with($name, 'sqlite_')) {
                    continue;
                }

                $checked++;
                $this->record($offenders, $name, "index `{$name}` on `{$table}`");
            }

        }

        foreach ($this->foreignKeyNamesFromMigrations() as $description => $name) {
            $checked++;
            $this->record($offenders, $name, $description);
        }

        // A guard against the check silently passing because it inspected
        // nothing — which is how a green schema test is worse than none.
        $this->assertGreaterThan(
            300,
            $checked,
            "Only {$checked} identifiers were inspected. The schema is larger than that, "
            .'so this check is not looking at what it claims to look at.',
        );

        $this->assertSame([], $offenders, sprintf(
            "%d schema identifier(s) exceed MySQL's %d-character limit and will fail with error 1059 on "
            ."`migrate:fresh` against MySQL, however green this suite is on SQLite:\n  - %s",
            count($offenders),
            self::MYSQL_MAX_IDENTIFIER_LENGTH,
            implode("\n  - ", $offenders),
        ));
    }

    /**
     * The effective name of every foreign key declared in every migration.
     *
     * Walks each migration line by line, tracking the table currently being
     * built by `Schema::create()` / `Schema::table()`, and resolves each foreign
     * key to the name MySQL will actually receive:
     *
     *  - `constrained($table, $column, 'explicit_name')` → the explicit name;
     *  - `constrained()` with fewer arguments, or `foreign('col')` → Laravel's
     *    derived `{table}_{column}_foreign`;
     *  - `foreign('col', 'explicit_name')` → the explicit name.
     *
     * A column named by a variable rather than a literal is skipped — the Spatie
     * permission migration does this, its identifiers are the package's, and
     * guessing at a runtime value would produce noise rather than a finding.
     *
     * @return array<string, string> description => effective identifier
     */
    private function foreignKeyNamesFromMigrations(): array
    {
        $names = [];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
            $file = basename($path);
            $table = null;

            $buffer = '';
            $startLine = 0;

            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $lineNumber => $rawLine) {
                if (preg_match("/Schema::(?:create|table)\('([a-z0-9_]+)'/i", $rawLine, $m) === 1) {
                    $table = $m[1];
                }

                // A foreign key is routinely declared across several lines:
                //
                //     $table->foreignId('dpc_proceeding_document_id')
                //         ->constrained('dpc_proceeding_documents', 'id', 'short_name')
                //         ->cascadeOnDelete();
                //
                // A line-by-line scan sees the `foreignId(` and misses the
                // explicit name two lines later, and then reports the derived
                // name as an offender — a false positive on the very constraint
                // this test exists for. Statements are accumulated to the
                // terminating `;` and matched whole.
                if (trim($buffer) === '') {
                    $startLine = $lineNumber + 1;
                }

                $buffer .= ' '.trim($rawLine);

                if (! str_contains($rawLine, ';')) {
                    continue;
                }

                $line = $buffer;
                $buffer = '';

                if ($table === null) {
                    continue;
                }

                $where = "{$file}:{$startLine}";

                // Explicitly named, by either mechanism. Checked first, because
                // an explicit name makes the derived one irrelevant.
                if (preg_match("/constrained\(\s*'[^']*'\s*,\s*'[^']*'\s*,\s*'([a-z0-9_]+)'/i", $line, $m) === 1
                    || preg_match("/->foreign\(\s*'[a-z0-9_]+'\s*,\s*'([a-z0-9_]+)'/i", $line, $m) === 1) {
                    $names["foreign key `{$m[1]}` on `{$table}` ({$where}, explicit)"] = $m[1];

                    continue;
                }

                // Left to Laravel: the derived name is what MySQL receives, and
                // is the string that overflowed in M8.
                if (preg_match("/->(?:foreignId|foreignIdFor|foreignUuid)\(\s*'([a-z0-9_]+)'/i", $line, $m) === 1
                    || preg_match("/->foreign\(\s*'([a-z0-9_]+)'\s*\)/i", $line, $m) === 1) {
                    $derived = $this->derivedIndexName($table, [$m[1]], 'foreign');
                    $names["foreign key `{$derived}` on `{$table}` ({$where}, derived)"] = $derived;
                }
            }
        }

        return $names;
    }

    /**
     * Mirrors `Illuminate\Database\Schema\Blueprint::createIndexName()`: the
     * table and columns joined by underscores, suffixed with the index type,
     * lowercased, with dots and hyphens collapsed to underscores.
     *
     * @param  list<string>  $columns
     */
    private function derivedIndexName(string $table, array $columns, string $type): string
    {
        $name = strtolower($table.'_'.implode('_', $columns).'_'.$type);

        return str_replace(['-', '.'], '_', $name);
    }

    /**
     * @param  list<string>  $offenders
     */
    private function record(array &$offenders, string $identifier, string $description): void
    {
        $length = strlen($identifier);

        if ($length > self::MYSQL_MAX_IDENTIFIER_LENGTH) {
            $offenders[] = "{$description} — {$length} characters";
        }
    }
}
