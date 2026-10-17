<?php

declare(strict_types=1);

namespace App\Domain\Committee\Services;

use App\Domain\Master\Models\CommitteeConfiguration;
use App\Domain\Master\Services\MasterCrudService;
use App\Domain\Shared\Services\ActivityTimelineService;

/**
 * Report — and, on the Administrator's instruction, correct — a Committee
 * Configuration whose size bounds predate change item 15.
 *
 * **The problem this exists for is a data problem, which is why it is not a
 * migration.** Item 15 fixes a DPC committee at three to six members besides the
 * Chairman. `CommitteeMasterSeeder` seeds that, and `config/dpc.php` carries the
 * same numbers as the fallback — so a fresh installation is correct. An
 * installation seeded *before* 2026-08-24 holds a row saying one to five, and that
 * row wins, because {@see CommitteeRuleResolver} reads the master first by design:
 * an Administrator who set a bound meant it.
 *
 * The consequence is the worst kind of gap. The rule the register asked for is in
 * force on a fresh installation and silently absent on a running one — and a
 * seeder cannot fix it, because seeders do not run on a live database.
 *
 * **So why not a migration?** Because a migration cannot tell the difference
 * between a bound left at the old default and a bound an Administrator chose. One
 * should be corrected and the other must not be touched, and only a person knows
 * which is which. This reports the difference, states both numbers, and applies
 * the configured ones **only when asked** — as the Administrator's own act, through
 * `MasterCrudService` so it is stamped and audited like any other master edit.
 */
class CommitteeBoundsReconciler
{
    public function __construct(
        private readonly MasterCrudService $crud,
        private readonly ActivityTimelineService $timeline,
    ) {}

    /**
     * The difference between what this configuration says and what the register
     * says, or null when they agree.
     *
     * Compared **as the resolver reads them** — besides the Chairman — rather than
     * as stored. A configuration of 4–7 with a mandatory Chairman and a
     * configuration-free installation on 3–6 are the same rule expressed two ways,
     * and reporting them as a difference would send an Administrator to correct
     * something already correct.
     *
     * @return array{stored: array{min: int, max: int}, configured: array{min: int, max: int}}|null
     */
    public function drift(CommitteeConfiguration $configuration): ?array
    {
        // The Chairman is mandatory by rule, so the stored whole-committee counts
        // always hold one seat more than the members besides the Chairman.
        $stored = [
            'min' => max(0, (int) $configuration->min_members - 1),
            'max' => max(0, (int) $configuration->max_members - 1),
        ];

        $configured = [
            'min' => (int) config('dpc.committee.min_members', 3),
            'max' => (int) config('dpc.committee.max_members', 6),
        ];

        return $stored === $configured ? null : ['stored' => $stored, 'configured' => $configured];
    }

    /**
     * Write the configured bounds onto this configuration.
     *
     * Stored **as whole-committee counts**, because that is what the column has
     * always meant and what the form shows; the conversion lives in one place and
     * this is the other side of it.
     */
    public function apply(CommitteeConfiguration $configuration): void
    {
        $min = (int) config('dpc.committee.min_members', 3) + 1;
        $max = (int) config('dpc.committee.max_members', 6) + 1;

        $this->crud->update($configuration, ['min_members' => $min, 'max_members' => $max]);

        $this->timeline->record(
            'committee_configuration.bounds_aligned',
            $configuration,
            sprintf('Committee size set to %d–%d members (change item 15).', $min, $max),
        );
    }
}
