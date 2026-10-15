<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ForeignKeyDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Cadre" leaves the system. Everything it named is a **Department Post**.
 *
 * Business ruling, 2026-08-24 (change items 3, 4 and 6). The word carried two
 * meanings at once — a service cadre in the establishment sense, and the
 * individual post a DPC promotes into — and the deck only ever means the second.
 * The register also settled what a Department Post *is*: a post name scoped to
 * one department, carrying its Pay Scale, its Class and its Promotion Duration.
 * Two departments may both have a "Secretary"; they are different posts, which
 * the pre-existing `unique(department_id, name)` already said.
 *
 * **The two reference columns get the register's terminology, not a mechanical
 * substitution.** Item 6 retires "Current Post" and "Feeder Post" in favour of
 * "Promotion To Post" and "Promotion From Post", and `post_to_cadre_id` /
 * `post_from_cadre_id` were the database's spelling of exactly that pair. The
 * same applies to a recruitment rule's and a Pre-DPC's feeder reference: a
 * feeder *is* the post officers are promoted from, so it is named that.
 *
 * **This renames; it does not drop and recreate.** `cadres` is referenced by
 * seven foreign keys across six tables, three of which (`dpc_proposals`,
 * `recruitment_rules`, `reservation_rosters`) carry approved records that a
 * tribunal may be shown. A drop-and-repopulate would reissue primary keys and
 * silently re-point that evidence. `id` values are untouched here, so every
 * approved proposal still names the post it named yesterday.
 *
 * **`promotion_duration_years` is the one genuinely new column**, and it is the
 * reason auto-population (item 5) can exist at all: before this, the duration a
 * feeder must serve lived only on a recruitment rule's feeder row, so the
 * proposal screen had nothing to read until a rule had been projected. Nullable
 * because the existing rows have no answer and inventing one would be inventing
 * a business rule.
 *
 * **Why the index dance below is in the order it is.** MySQL requires an index
 * on a foreign-key column, and on three of these tables the *only* such index is
 * the composite unique whose name contains "cadre". Dropping it first fails with
 * errno 150; so each replacement index is created before its predecessor is
 * dropped, leaving the constraint covered throughout.
 */
return new class extends Migration
{
    /**
     * Every column that named a cadre, and what it is called now.
     *
     * @var array<string, array<string, string>>
     */
    private const COLUMN_RENAMES = [
        'recruitment_rules' => ['cadre_id' => 'post_id'],
        'recruitment_rule_feeders' => ['feeder_cadre_id' => 'promotion_from_post_id'],
        'reservation_rosters' => ['cadre_id' => 'post_id'],
        'pre_dpc_posts' => ['cadre_id' => 'post_id'],
        'pre_dpc_post_feeders' => ['feeder_cadre_id' => 'promotion_from_post_id'],
        'dpc_proposals' => [
            'post_to_cadre_id' => 'promotion_to_post_id',
            'post_from_cadre_id' => 'promotion_from_post_id',
        ],
    ];

    /**
     * Foreign keys onto the renamed table, as `[table, column, on-delete]`.
     *
     * The on-delete rule of each is preserved exactly as its creating migration
     * declared it: a proposal's post reference is RESTRICT (an approved proposal
     * must not lose the post it names), a rule's or roster's is CASCADE, and a
     * Pre-DPC's is SET NULL.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const FOREIGN_KEYS = [
        ['recruitment_rules', 'post_id', 'cascade'],
        ['recruitment_rule_feeders', 'promotion_from_post_id', 'cascade'],
        ['reservation_rosters', 'post_id', 'cascade'],
        ['pre_dpc_posts', 'post_id', 'null'],
        ['pre_dpc_post_feeders', 'promotion_from_post_id', 'null'],
        ['dpc_proposals', 'promotion_to_post_id', 'restrict'],
        ['dpc_proposals', 'promotion_from_post_id', 'restrict'],
    ];

    /**
     * The renamed table's *own* foreign keys, as `[column, references,
     * on-delete]`.
     *
     * These point outward rather than inward, so nothing above covers them — and
     * a table rename leaves their derived names behind exactly as it leaves the
     * inbound ones behind. Without this the migrated schema still carries
     * `cadres_created_by_foreign` on a table called `department_posts`, which is
     * the kind of residue that makes the next person wonder whether the rename
     * finished.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const OWN_FOREIGN_KEYS = [
        ['department_id', 'departments', 'cascade'],
        ['pay_scale_id', 'pay_scales', 'null'],
        ['created_by', 'users', 'null'],
        ['updated_by', 'users', 'null'],
        ['deleted_by', 'users', 'null'],
    ];

    /**
     * The same seven constraints under the names they carried before this
     * migration — which is what `down()` has to restore and what `up()` has to
     * drop, because MySQL keeps a constraint's name across a rename and it then
     * says "cadre" about a column that no longer exists.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const LEGACY_FOREIGN_KEYS = [
        ['recruitment_rules', 'cadre_id', 'cascade'],
        ['recruitment_rule_feeders', 'feeder_cadre_id', 'cascade'],
        ['reservation_rosters', 'cadre_id', 'cascade'],
        ['pre_dpc_posts', 'cadre_id', 'null'],
        ['pre_dpc_post_feeders', 'feeder_cadre_id', 'null'],
        ['dpc_proposals', 'post_to_cadre_id', 'restrict'],
        ['dpc_proposals', 'post_from_cadre_id', 'restrict'],
    ];

    public function up(): void
    {
        Schema::rename('cadres', 'department_posts');

        foreach (self::COLUMN_RENAMES as $table => $renames) {
            $this->rename($table, $renames);
        }

        // `department_posts` own identity: the unique key, and the post's
        // Promotion Duration (item 4).
        $this->swapUnique('department_posts', ['department_id', 'name'], 'department_posts_department_id_name_unique');

        Schema::table('department_posts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('promotion_duration_years')
                ->nullable()
                ->after('pay_scale_id');
        });

        $this->swapUnique('recruitment_rules', ['post_id', 'version'], 'recruitment_rules_post_id_version_unique');
        $this->swapUnique('recruitment_rule_feeders', ['recruitment_rule_id', 'promotion_from_post_id'], 'rr_feeders_rule_from_post_unique');
        $this->swapUnique('reservation_rosters', ['department_id', 'post_id', 'name'], 'reservation_rosters_department_post_name_unique');

        // The constraints still carry their pre-rename names. Drop them by those
        // names and re-declare them, so the derived name matches the column.
        $this->dropForeignKeys(self::LEGACY_FOREIGN_KEYS);
        $this->addForeignKeys(self::FOREIGN_KEYS, 'department_posts');

        $this->renameOwnConstraints('department_posts', from: 'cadres', to: 'department_posts');
    }

    public function down(): void
    {
        // While the table is still called `department_posts` — the rename back
        // happens further down, and `Schema::table()` needs the name it has now.
        $this->renameOwnConstraints('department_posts', from: 'department_posts', to: 'cadres');

        $this->dropForeignKeys(self::FOREIGN_KEYS);

        // Named for the columns they will carry again in a moment; MySQL follows
        // an index definition through a column rename, so restoring the name now
        // and the column next leaves both correct.
        $this->swapUnique('reservation_rosters', ['department_id', 'post_id', 'name'], 'reservation_rosters_department_id_cadre_id_name_unique');
        $this->swapUnique('recruitment_rule_feeders', ['recruitment_rule_id', 'promotion_from_post_id'], 'rr_feeders_rule_cadre_unique');
        $this->swapUnique('recruitment_rules', ['post_id', 'version'], 'recruitment_rules_cadre_id_version_unique');

        Schema::table('department_posts', function (Blueprint $table): void {
            $table->dropColumn('promotion_duration_years');
        });

        $this->swapUnique('department_posts', ['department_id', 'name'], 'cadres_department_id_name_unique');

        foreach (self::COLUMN_RENAMES as $table => $renames) {
            $this->rename($table, array_flip($renames));
        }

        Schema::rename('department_posts', 'cadres');

        $this->addForeignKeys(self::LEGACY_FOREIGN_KEYS, 'cadres');
    }

    /**
     * @param  array<string, string>  $renames
     */
    private function rename(string $table, array $renames): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($renames): void {
            foreach ($renames as $from => $to) {
                $blueprint->renameColumn($from, $to);
            }
        });
    }

    /**
     * Give a unique index the name its columns now imply.
     *
     * Creating the replacement *before* dropping the incumbent is not tidiness:
     * on MySQL these indexes are the only cover for a foreign key on their
     * leading column, and an uncovered foreign key cannot exist even for the
     * duration of one statement.
     *
     * @param  list<string>  $columns
     */
    private function swapUnique(string $table, array $columns, string $name): void
    {
        $existing = collect(Schema::getIndexes($table))
            ->first(static fn (array $index): bool => (bool) $index['unique']
                && $index['columns'] === $columns
                && $index['name'] !== $name);

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name, $existing): void {
            $blueprint->unique($columns, $name);

            if ($existing !== null) {
                $blueprint->dropIndex((string) $existing['name']);
            }
        });
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $keys
     */
    private function dropForeignKeys(array $keys): void
    {
        if (! $this->namesForeignKeys()) {
            return;
        }

        foreach ($keys as [$table, $column]) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $column): void {
                $blueprint->dropForeign("{$table}_{$column}_foreign");
            });
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $keys
     */
    private function addForeignKeys(array $keys, string $references): void
    {
        if (! $this->namesForeignKeys()) {
            return;
        }

        foreach ($keys as [$table, $column, $onDelete]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $references, $onDelete): void {
                $this->applyOnDelete($blueprint->foreign($column)->references('id')->on($references), $onDelete);
            });
        }
    }

    /**
     * Re-derive the constraint and index names the table owns itself.
     *
     * `$on` is the name the table answers to *right now* — which in `down()` is
     * still the new one, because the rename back happens after this. `$from` and
     * `$to` are the prefixes the constraint names are derived from, and they are
     * separate arguments precisely because they are not always the current table
     * name. The `status` index is created before its predecessor is dropped for
     * the same reason as {@see swapUnique()}: it backs nothing here, but keeping
     * both paths identical to read is worth more than saving a statement.
     */
    private function renameOwnConstraints(string $on, string $from, string $to): void
    {
        if (! $this->namesForeignKeys()) {
            return;
        }

        foreach (self::OWN_FOREIGN_KEYS as [$column, $references, $onDelete]) {
            Schema::table($on, function (Blueprint $table) use ($from, $to, $column, $references, $onDelete): void {
                $table->dropForeign("{$from}_{$column}_foreign");

                $this->applyOnDelete(
                    $table->foreign($column, "{$to}_{$column}_foreign")->references('id')->on($references),
                    $onDelete,
                );
            });
        }

        Schema::table($on, function (Blueprint $table) use ($from, $to): void {
            $table->index(['status'], "{$to}_status_index");
            $table->dropIndex("{$from}_status_index");
        });
    }

    /**
     * Give a rebuilt constraint the delete behaviour its table declares.
     *
     * One place rather than three: the inbound keys, the table's own keys and the
     * legacy names in `down()` all rebuild constraints, and the whole purpose of
     * this migration is that they come back *identical*. A behaviour spelled out
     * separately in each loop is a behaviour that can drift in one of them, which
     * on a foreign key means a delete that cascades where it should have been
     * refused.
     */
    private function applyOnDelete(ForeignKeyDefinition $foreign, string $onDelete): void
    {
        match ($onDelete) {
            'cascade' => $foreign->cascadeOnDelete(),
            'null' => $foreign->nullOnDelete(),
            default => $foreign->restrictOnDelete(),
        };
    }

    /**
     * Whether this engine has named foreign keys to correct at all.
     *
     * **SQLite does not, and that is not a gap.** It stores foreign keys inline
     * and without names — `Schema::getForeignKeys()` reports `name => null` for
     * every one of them — and rewrites each `REFERENCES` clause itself when a
     * table or a column is renamed. There is no stale name there to fix, and its
     * grammar cannot drop a constraint by one.
     */
    private function namesForeignKeys(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
