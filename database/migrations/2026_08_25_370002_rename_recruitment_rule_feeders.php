<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `recruitment_rule_feeders` → `recruitment_rule_sources` (suggestion 7).
 *
 * Change item 6 replaced *Feeder Post* with *Promotion From Post* everywhere a user
 * can see, and renamed the columns to match. What it did not rename — because the
 * register named the columns and not the table — is the table itself, its model and
 * the screen's own identifiers. The result is one concept under two vocabularies,
 * which is how the old one comes back.
 *
 * **Why `_sources` and not `_promotion_sources`.** The longer name was written
 * first and does not fit: MySQL caps an identifier at 64 characters, and
 * `recruitment_rule_promotion_sources_promotion_from_post_id_foreign` is 65. The
 * choice is a shorter table name or hand-written constraint names on one table
 * while every other table in the schema uses Laravel's derivation — and a schema
 * where one table names its keys differently is a schema whose next migration gets
 * it wrong. So the table is shorter.
 *
 * **Mechanically the same problem as migration `360001`.** MySQL derives a
 * constraint's name from the table it was created on and keeps that name across a
 * rename, so the keys are dropped **by their real names, read from
 * `information_schema`** rather than by a derivation that may not match: the
 * `promotion_from_post_id` key has been renamed once already, and guessing its name
 * is what made the first attempt at this migration fail halfway.
 *
 * **Both directions are idempotent and complete.** A partly-applied run — table
 * renamed, keys not yet rebuilt — is finished rather than skipped, because a guard
 * that returns early on "the table is already renamed" is a guard that reports
 * success on a table with no foreign keys.
 */
return new class extends Migration
{
    private const FROM = 'recruitment_rule_feeders';

    private const TO = 'recruitment_rule_sources';

    /**
     * The table's own foreign keys, as `[column, references, on-delete]`.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const FOREIGN_KEYS = [
        ['recruitment_rule_id', 'recruitment_rules', 'cascade'],
        ['promotion_from_post_id', 'department_posts', 'restrict'],
        ['qualification_id', 'qualifications', 'null'],
    ];

    public function up(): void
    {
        $this->move(self::FROM, self::TO);
    }

    public function down(): void
    {
        $this->move(self::TO, self::FROM);
    }

    private function move(string $from, string $to): void
    {
        if (Schema::hasTable($from)) {
            $this->dropForeignKeys($from);
            Schema::rename($from, $to);
        }

        if (! Schema::hasTable($to)) {
            return;
        }

        // Rebuilt whether or not this run did the renaming, so a half-finished
        // attempt is completed rather than declared done.
        $this->addForeignKeys($to);
    }

    private function dropForeignKeys(string $table): void
    {
        if (! $this->namesForeignKeys()) {
            // SQLite implements a rename by rebuilding the table and derives no
            // constraint names, so there is nothing to correct.
            return;
        }

        foreach ($this->existingForeignKeys($table) as $name) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($name));
        }
    }

    private function addForeignKeys(string $table): void
    {
        if (! $this->namesForeignKeys()) {
            return;
        }

        $existing = $this->existingForeignKeys($table);

        foreach (self::FOREIGN_KEYS as [$column, $references, $onDelete]) {
            if (in_array("{$table}_{$column}_foreign", $existing, true)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column, $references, $onDelete): void {
                $foreign = $blueprint->foreign($column)->references('id')->on($references);

                match ($onDelete) {
                    'cascade' => $foreign->cascadeOnDelete(),
                    'null' => $foreign->nullOnDelete(),
                    default => $foreign->restrictOnDelete(),
                };
            });
        }
    }

    /**
     * The foreign keys this table actually has, by name.
     *
     * Read rather than derived: `promotion_from_post_id` was `feeder_cadre_id`
     * until migration `360002`, so its constraint may still carry either name.
     *
     * @return list<string>
     */
    private function existingForeignKeys(string $table): array
    {
        $rows = DB::select(
            'SELECT DISTINCT CONSTRAINT_NAME AS name
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table],
        );

        return array_map(static fn (object $row): string => (string) $row->name, $rows);
    }

    private function namesForeignKeys(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};
