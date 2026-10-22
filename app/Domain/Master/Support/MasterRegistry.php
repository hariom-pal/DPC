<?php

declare(strict_types=1);

namespace App\Domain\Master\Support;

use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\DepartmentPost;
use App\Domain\Master\Models\Designation;
use App\Domain\Master\Models\District;
use App\Domain\Master\Models\DpcType;
use App\Domain\Master\Models\PayScale;
use App\Domain\Master\Models\Qualification;
use App\Domain\Master\Models\RecordReason;
use App\Domain\Master\Models\ReservationCategory;
use App\Domain\Master\Models\ReviewReason;
use App\Domain\Master\Models\SealedCoverReason;
use Illuminate\Database\Eloquent\Model;

/**
 * Every master that supports search, CSV export and bulk upload.
 *
 * **What is deliberately absent is as much a decision as what is present.**
 * Recruitment Rules, Committee Configurations, Reservation Policies and Rosters are
 * not here: each is a versioned instrument with its own approval or supersession
 * story, and a spreadsheet that overwrites one by `code` would quietly rewrite an
 * order somebody signed. They keep their own screens, where the rules that govern
 * them are enforced. What is listed here is *reference data* — the rows a dropdown
 * is filled from.
 *
 * `status` is a column on every one of them, and is included in every export and
 * every template, because withdrawing a row is the ordinary way a master changes:
 * a row a rule already cites must go Inactive rather than be deleted.
 */
final class MasterRegistry
{
    /**
     * @return array<string, MasterDefinition>
     */
    public static function all(): array
    {
        $definitions = [
            self::bilingual('qualifications', 'Qualifications', Qualification::class, [
                ['code' => 'BED', 'name_en' => 'B.Ed.', 'name_hi' => 'बी.एड.', 'status' => 'active'],
                ['code' => 'PGBED', 'name_en' => 'Post-Graduate with B.Ed.', 'name_hi' => 'स्नातकोत्तर सह बी.एड.', 'status' => 'active'],
            ]),
            /*
             | The sample is the **seeded** code, lowercase. An uppercase `FRESH`
             | here matched the seeded `fresh` on MySQL's case-insensitive collation,
             | and importing the sample then rewrote the stored code to `FRESH` —
             | which the Fresh DPC screen, comparing against `fresh`, refused.
             */
            self::bilingual('dpc-types', 'DPC Types', DpcType::class, [
                ['code' => 'fresh', 'name_en' => 'Fresh DPC', 'name_hi' => 'फ्रेश डी.पी.सी.', 'status' => 'active'],
            ]),
            self::bilingual('review-reasons', 'Review Reasons', ReviewReason::class, [
                ['code' => 'COURT', 'name_en' => 'Court direction', 'name_hi' => 'न्यायालयीन निर्देश', 'status' => 'active'],
            ]),

            new MasterDefinition(
                key: 'designations',
                label: 'Designations',
                model: Designation::class,
                columns: ['code' => 'code', 'name' => 'name', 'status' => 'status'],
                required: ['name'],
                searchable: ['name', 'code'],
                unique: ['name'],
                sample: [
                    ['code' => 'DS', 'name' => 'Deputy Secretary', 'status' => 'active'],
                    ['code' => 'US', 'name' => 'Under Secretary', 'status' => 'active'],
                ],
            ),

            new MasterDefinition(
                key: 'pay-scales',
                label: 'Pay Scales',
                model: PayScale::class,
                columns: [
                    'code' => 'code', 'label' => 'label',
                    // `level` and `grade_pay` are what `PromotionHierarchyService`
                    // compares, and a master without them makes two of its three
                    // checks unanswerable — so the template asks for them.
                    'level' => 'level', 'grade_pay' => 'grade_pay', 'status' => 'status',
                ],
                required: ['code', 'label'],
                searchable: ['label', 'code'],
                unique: ['code'],
                sample: [
                    ['code' => 'L12', 'label' => 'Level-12 (78800-209200)', 'level' => '12', 'grade_pay' => '7600', 'status' => 'active'],
                    ['code' => 'L13', 'label' => 'Level-13 (123100-215900)', 'level' => '13', 'grade_pay' => '8700', 'status' => 'active'],
                ],
            ),

            new MasterDefinition(
                key: 'districts',
                label: 'Districts',
                model: District::class,
                columns: [
                    'lgd_code' => 'lgd_code', 'state_lgd_code' => 'state_lgd_code',
                    'name_en' => 'name_en', 'name_hi' => 'name_hi', 'status' => 'status',
                ],
                required: ['lgd_code', 'name_en'],
                searchable: ['name_en', 'name_hi', 'lgd_code'],
                unique: ['lgd_code'],
                sample: [
                    ['lgd_code' => '387', 'state_lgd_code' => '22', 'name_en' => 'Raipur', 'name_hi' => 'रायपुर', 'status' => 'active'],
                ],
            ),

            new MasterDefinition(
                key: 'reservation-categories',
                label: 'Reservation Categories',
                model: ReservationCategory::class,
                columns: [
                    'code' => 'code', 'name_en' => 'name_en', 'name_hi' => 'name_hi',
                    'is_reserved' => 'is_reserved', 'status' => 'status',
                ],
                required: ['code', 'name_en'],
                searchable: ['name_en', 'name_hi', 'code'],
                unique: ['code'],
                sample: [
                    ['code' => 'SC', 'name_en' => 'Scheduled Caste', 'name_hi' => 'अनुसूचित जाति', 'is_reserved' => '1', 'status' => 'active'],
                    ['code' => 'UR', 'name_en' => 'Unreserved', 'name_hi' => 'अनारक्षित', 'is_reserved' => '0', 'status' => 'active'],
                ],
            ),

            new MasterDefinition(
                key: 'record-reasons',
                label: 'Record Reasons',
                model: RecordReason::class,
                columns: [
                    'code' => 'code', 'name_en' => 'name_en', 'name_hi' => 'name_hi',
                    'requires_remarks' => 'requires_remarks', 'sort_order' => 'sort_order', 'status' => 'status',
                ],
                required: ['code', 'name_en'],
                searchable: ['name_en', 'name_hi', 'code'],
                unique: ['code'],
                sample: [
                    ['code' => 'CR_MISSING', 'name_en' => 'CR not available', 'name_hi' => 'सीआर उपलब्ध नहीं', 'requires_remarks' => '1', 'sort_order' => '10', 'status' => 'active'],
                ],
            ),

            new MasterDefinition(
                key: 'sealed-cover-reasons',
                label: 'Sealed Cover Reasons',
                model: SealedCoverReason::class,
                columns: [
                    'code' => 'code', 'name_en' => 'name_en', 'name_hi' => 'name_hi', 'effect' => 'effect',
                    'requires_document' => 'requires_document', 'sort_order' => 'sort_order', 'status' => 'status',
                ],
                required: ['code', 'name_en', 'effect'],
                searchable: ['name_en', 'name_hi', 'code'],
                unique: ['code'],
                sample: [
                    ['code' => 'de_chargesheet', 'name_en' => 'Departmental enquiry instituted and charge-sheet issued', 'name_hi' => 'विभागीय जाँच प्रारंभ एवं आरोप-पत्र जारी', 'effect' => 'sealed_cover', 'requires_document' => '1', 'sort_order' => '10', 'status' => 'active'],
                ],
            ),

            new MasterDefinition(
                key: 'departments',
                label: 'Departments',
                model: Department::class,
                columns: [
                    'code' => 'code', 'name' => 'name', 'district' => 'district_id',
                    'place' => 'place', 'pin' => 'pin', 'email' => 'email', 'status' => 'status',
                ],
                required: ['code', 'name'],
                searchable: ['name', 'code'],
                unique: ['code'],
                // The CSV names a district; the importer resolves it to an id, so
                // nobody has to know the master's primary keys to fill a spreadsheet.
                lookups: ['district_id' => 'districts.name_en'],
                sample: [
                    ['code' => 'SE', 'name' => 'School Education Department', 'district' => 'Raipur', 'place' => 'Raipur', 'pin' => '492001', 'email' => 'se@cg.gov.in', 'status' => 'active'],
                ],
            ),

            new MasterDefinition(
                key: 'department-posts',
                label: 'Department Post Mapping',
                model: DepartmentPost::class,
                columns: [
                    'department' => 'department_id', 'post_name' => 'name',
                    'class_level' => 'class_level', 'pay_scale' => 'pay_scale_id',
                    'promotion_duration_years' => 'promotion_duration_years',
                    'effective_from' => 'effective_from', 'status' => 'status',
                ],
                required: ['department', 'post_name', 'class_level'],
                searchable: ['name'],
                unique: ['department_id', 'name'],
                lookups: ['department_id' => 'departments.name', 'pay_scale_id' => 'pay_scales.code'],
                sample: [
                    ['department' => 'School Education Department', 'post_name' => 'Block Education Officer', 'class_level' => 'II', 'pay_scale' => 'L12', 'promotion_duration_years' => '5', 'effective_from' => '2020-01-01', 'status' => 'active'],
                ],
            ),
        ];

        $keyed = [];

        foreach ($definitions as $definition) {
            $keyed[$definition->key] = $definition;
        }

        return $keyed;
    }

    public static function find(string $key): ?MasterDefinition
    {
        return self::all()[$key] ?? null;
    }

    /**
     * The shape most of these masters share: a code, a name in both languages, and
     * a status.
     *
     * @param  class-string<Model>  $model
     * @param  list<array<string, string>>  $sample
     */
    private static function bilingual(string $key, string $label, string $model, array $sample): MasterDefinition
    {
        return new MasterDefinition(
            key: $key,
            label: $label,
            model: $model,
            columns: ['code' => 'code', 'name_en' => 'name_en', 'name_hi' => 'name_hi', 'status' => 'status'],
            required: ['code', 'name_en'],
            searchable: ['name_en', 'name_hi', 'code'],
            unique: ['code'],
            sample: $sample,
        );
    }
}
