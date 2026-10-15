<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\Dpc\Services\DpcProposalCompletenessService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demonstration database must be buildable.
 *
 * **This exists because its absence let `demo:seed` break for a week without
 * anything going red.** Every business rule the seeder walks through arrived
 * after the seeder was written, and three of them refuse the data it was still
 * producing: the vacancy breakdown became *derived* from the Working Officer
 * chart on 2026-09-02, so a chart that did not account for every sanctioned
 * chair stopped balancing; `status` and `source` arrived on the same date, and a
 * chart that omitted them seated every officer on no quota at all; and an
 * allotment may no longer be made to somebody absent from the DPC's own
 * committee. The suite was 1447 tests green throughout, because not one of them
 * seeded `DemoSeeder`.
 *
 * The assertions are deliberately about the *gates*, not the numbers. Pinning
 * "vacancy 4" here would make every legitimate change to the demo data a failing
 * test; pinning "the proposal the seeder built would be accepted by the
 * completeness gate" is the thing that must never stop being true, and it is
 * exactly what a presenter finds out the hard way otherwise.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demonstration_database_builds(): void
    {
        $this->seed(DemoSeeder::class);

        $proposals = DpcProposal::query()->get();

        $this->assertGreaterThanOrEqual(
            9,
            $proposals->count(),
            'The demo seeder is meant to leave a DPC at each stage of the workflow.',
        );
    }

    public function test_every_seeded_proposal_would_pass_the_completeness_gate(): void
    {
        $this->seed(DemoSeeder::class);

        $completeness = app(DpcProposalCompletenessService::class);
        $failures = [];

        foreach (DpcProposal::with(['promotionToPost', 'promotionFromPost'])->get() as $proposal) {
            foreach ($completeness->checklist($proposal) as $section) {
                if (($section['complete'] ?? false) === true) {
                    continue;
                }

                $failures[] = sprintf(
                    '%s <- %s [%s]: %s — %s',
                    $proposal->promotionToPost?->name ?? '?',
                    $proposal->promotionFromPost?->name ?? '?',
                    $proposal->status?->value ?? '?',
                    $section['screen'] ?? $section['key'],
                    $section['detail'] ?? '',
                );
            }
        }

        $this->assertSame(
            [],
            $failures,
            "The seeder built a proposal the portal would refuse:\n".implode("\n", $failures),
        );
    }
}
