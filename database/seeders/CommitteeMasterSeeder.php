<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Master\Models\CommitteeConfiguration;
use App\Enums\MasterStatus;
use Illuminate\Database\Seeder;

/**
 * Default DPC Committee rules: the size, and the attributes the committee must
 * cover (SC, ST, OBC, female, one Bhar Sadhak Sachiv, one Vibhag Sachiv) taken
 * from configuration. The CGPSC Chairman is mandatory by rule, not by setting. The
 * CGPSC Administrator changes these on the DPC Committee Rules screen — this seed
 * only ensures the feature works out of the box.
 *
 * **The bounds changed on 2026-08-24 to the ones change item 15 states**: a
 * minimum of three and a maximum of six members *besides* the Chairman. They are
 * stored here as whole-committee counts, 4 and 7, because that is what the column
 * has always meant and what the admin screen shows; the conversion happens in
 * one place, in `CommitteeRuleResolver::besidesChairman()`.
 *
 * An installation seeded before that date still holds 1–5, and this seeder will
 * not reach it: `updateOrCreate` only runs when seeding. Correcting a live
 * installation is the Administrator's edit on the Committee Configuration screen,
 * deliberately rather than by migration — overwriting a bound an Administrator
 * chose is not something a deployment should do silently.
 *
 * voting_method/majority_type/tie_break_rule/quorum are required columns
 * not yet exercised by any business logic (voting/quorum belong to the
 * M7 Proceeding milestone; the PPT and decision Q16 describe a single
 * allotted Chairman/Member conducting, not multi-member voting) — seeded
 * with placeholder values to satisfy the schema, to be revisited when M7
 * actually implements proceeding conduct.
 */
class CommitteeMasterSeeder extends Seeder
{
    public function run(): void
    {
        CommitteeConfiguration::query()->updateOrCreate(
            ['status' => MasterStatus::Active->value],
            [
                'min_members' => 4,
                'max_members' => 7,
                'required_categories' => array_values((array) config('dpc.committee.required_categories', [])),
                'requires_reserved_category' => (bool) config('dpc.committee.require_reserved_category', false),
            ],
        );
    }
}
