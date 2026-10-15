<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Master\Models\Qualification;
use App\Enums\MasterStatus;

/**
 * A live qualification for a Pre-DPC feeder to prescribe.
 *
 * `promotion_sources.*.qualification_ids` became **required** on 2026-09-06
 * (business: *"promotion to post mandatory and qualification is also"*). It had been
 * optional since the register of 2026-08-26 introduced it, so every Pre-DPC payload
 * written before that date omits it — and a test about the recruitment split, or the
 * numbering, or who may approve, should not have to know the Qualification master
 * exists in order to keep passing.
 *
 * Find-or-create rather than a factory call, so a test that seeds the lookup masters
 * and one that seeds nothing both get an id, and calling it twice in one test yields
 * the same row rather than two.
 */
trait PrescribesQualifications
{
    /**
     * @return list<int>
     */
    protected function qualificationIds(): array
    {
        $qualification = Qualification::query()->firstOrCreate(
            ['name_en' => 'Graduate'],
            ['name_hi' => 'स्नातक', 'status' => MasterStatus::Active->value],
        );

        // A qualification the master has withdrawn is refused by the request, and a
        // shared fixture that silently became inactive would fail every caller with
        // a message about the master rather than about the test.
        if ($qualification->status !== MasterStatus::Active) {
            $qualification->forceFill(['status' => MasterStatus::Active->value])->save();
        }

        return [$qualification->id];
    }
}
