<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Master\Models\CommitteeConfiguration;
use App\Domain\Master\Models\CommitteeType;
use App\Enums\MasterStatus;
use Illuminate\Database\Seeder;

/**
 * Default DPC Committee composition rules (PPT S15; architect decision
 * 2026-08-13, M4 Phase 2): Chairman mandatory, external members permitted.
 * CGPSC Admin can change this via the existing M2 admin screen — this seed only
 * ensures the feature works out of the box.
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
        $active = MasterStatus::Active->value;

        $type = CommitteeType::query()->updateOrCreate(
            ['code' => 'dpc_committee'],
            ['name_en' => 'DPC Committee', 'name_hi' => 'डी.पी.सी. समिति', 'status' => $active],
        );

        CommitteeConfiguration::query()->updateOrCreate(
            ['committee_type_id' => $type->id, 'status' => $active],
            [
                'min_members' => 4,
                'max_members' => 7,
                'chairman_mandatory' => true,
                'quorum' => 1,
                'voting_method' => 'chairman_decision',
                'majority_type' => 'not_applicable',
                'tie_break_rule' => 'chairman_decision',
                'digital_signature_required' => true,
                'remarks_required' => false,
                'external_member_allowed' => true,
                'secretary_required' => false,
            ],
        );
    }
}
