<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A soft-deleted record is deleted (2026-11-03): "kuch bhi soft delete hai wo create
 * ho sakta hai but wo log main rahe — soft delete matlab delete hi hai except trail
 * log".
 *
 * The deleted row stays, for the trail, and no longer holds its unique values: the
 * same code, e-mail or name can be entered again as a **new** record.
 *
 * **`live_flag` is generated from `deleted_at`**: `1` on a live row, NULL on a
 * deleted one, the `active_flag` pattern of `department_posts`. Every unique index
 * below gains it, so it reads *at most one live row per value, any number of
 * deleted ones*, because NULLs do not collide in MySQL or SQLite. Generated, so a
 * delete by any path (Eloquent, a query, a cleanup command) releases the value.
 *
 * Only values a person enters or a counter restarts. A uuid, the DPC code, the
 * Circulation code and the Pre-DPC number come from sequences that never repeat.
 *
 * Each wider index is created before the old one is dropped: MySQL refuses to drop
 * the only index covering a foreign key (errno 1553).
 */
return new class extends Migration
{
    /** table => [current unique index => its replacement] */
    private const INDEXES = [
        'users' => [
            'users_email_unique' => 'users_email_live_unique',
            'users_mobile_unique' => 'users_mobile_live_unique',
            'users_employee_number_unique' => 'users_employee_number_live_unique',
        ],
        'departments' => [
            'departments_code_unique' => 'departments_code_live_unique',
            'departments_name_unique' => 'departments_name_live_unique',
        ],
        'designations' => [
            'designations_code_unique' => 'designations_code_live_unique',
            'designations_name_unique' => 'designations_name_live_unique',
        ],
        'districts' => [
            'districts_lgd_code_unique' => 'districts_lgd_code_live_unique',
            'districts_name_en_unique' => 'districts_name_en_live_unique',
        ],
        'pay_scales' => [
            'pay_scales_code_unique' => 'pay_scales_code_live_unique',
            'pay_scales_label_unique' => 'pay_scales_label_live_unique',
        ],
        'dpc_types' => ['dpc_types_code_unique' => 'dpc_types_code_live_unique'],
        'qualifications' => ['qualifications_code_unique' => 'qualifications_code_live_unique'],
        'record_reasons' => ['record_reasons_code_unique' => 'record_reasons_code_live_unique'],
        'reservation_categories' => ['reservation_categories_code_unique' => 'reservation_categories_code_live_unique'],
        'review_reasons' => ['review_reasons_code_unique' => 'review_reasons_code_live_unique'],
        'sealed_cover_reasons' => ['sealed_cover_reasons_code_unique' => 'sealed_cover_reasons_code_live_unique'],
        'service_classes' => ['service_classes_code_unique' => 'service_classes_code_live_unique'],
        'vacancy_types' => ['vacancy_types_code_unique' => 'vacancy_types_code_live_unique'],
        'reservation_rosters' => ['reservation_rosters_department_post_name_unique' => 'reservation_rosters_department_post_name_live_unique'],
        'department_posts' => ['department_posts_active_mapping_unique' => 'department_posts_active_mapping_live_unique'],
        // A deleted draft no longer holds the exercise, or its version number.
        'pre_dpc_proposals' => [
            'pre_dpc_active_exercise_unique_v2' => 'pre_dpc_active_exercise_live_unique',
            'pre_dpc_exercise_version_unique_v2' => 'pre_dpc_exercise_version_live_unique',
        ],
        'recruitment_rules' => ['recruitment_rules_post_id_version_unique' => 'recruitment_rules_post_version_live_unique'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedTinyInteger('live_flag')->nullable()
                    ->virtualAs('CASE WHEN deleted_at IS NULL THEN 1 END');
            });

            $columns = $this->columns($table);

            foreach ($indexes as $old => $new) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique([...$columns[$old], 'live_flag'], $new));
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($old));
            }
        }
    }

    /**
     * Fails where a value was entered again after a delete: the old index cannot
     * hold both rows, and neither may be removed (the deleted one is the trail).
     */
    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            $columns = $this->columns($table);

            foreach ($indexes as $old => $new) {
                $original = array_values(array_diff($columns[$new], ['live_flag']));
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($original, $old));
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($new));
            }

            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('live_flag'));
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function columns(string $table): array
    {
        $columns = [];

        foreach (Schema::getIndexes($table) as $index) {
            $columns[$index['name']] = $index['columns'];
        }

        return $columns;
    }
};
