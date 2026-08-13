<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Master\Models\CommitteeConfiguration;
use App\Domain\Master\Models\CommitteeType;
use App\Enums\MasterStatus;
use Illuminate\Database\Seeder;

/**
 * Default DPC Committee composition rules (PPT S15; architect decision
 * 2026-08-13, M4 Phase 2): Chairman mandatory, 1–5 members, external
 * members permitted. CGPSC Admin can change this via the existing M2
 * admin screen — this seed only ensures the feature works out of the box.
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
                'min_members' => 1,
                'max_members' => 5,
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
